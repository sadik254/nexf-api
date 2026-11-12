<?php

namespace App\Services;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SupportInboxService
{
    private const ATTACHMENT_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'video/mp4',
        'video/webm',
    ];

    public function __construct(private MediaUploadService $uploads) {}

    public function fetchUnread(): int
    {
        if (! function_exists('imap_open')) {
            Log::warning('Support inbox polling skipped because PHP IMAP is unavailable.');

            return 0;
        }

        $config = config('support.inbox');
        if (blank($config['host'] ?? null) || blank($config['username'] ?? null) || blank($config['password'] ?? null)) {
            return 0;
        }

        $flags = '/imap/'.($config['encryption'] === 'ssl' ? 'ssl' : 'tls');
        $mailbox = '{'.$config['host'].':'.$config['port'].$flags.'}'.$config['mailbox'];
        $inbox = @imap_open($mailbox, $config['username'], $config['password']);
        if (! $inbox) {
            Log::warning('Support inbox connection failed.', ['error' => imap_last_error()]);

            return 0;
        }

        $processed = 0;
        try {
            foreach (imap_search($inbox, 'UNSEEN') ?: [] as $messageNumber) {
                try {
                    $header = imap_headerinfo($inbox, $messageNumber);
                    $from = $this->fromAddress($header);
                    $subject = $this->decodeHeader((string) ($header->subject ?? ''));
                    $overview = imap_fetch_overview($inbox, (string) $messageNumber, 0)[0] ?? null;
                    $messageId = trim((string) ($overview->message_id ?? ''));
                    $message = $this->message($inbox, $messageNumber);
                    $headers = imap_fetchheader($inbox, $messageNumber) ?: '';
                    if ($this->ingest($from, $subject, $message['body'], $messageId ?: null, $message['attachments'], $headers)) {
                        $processed++;
                    }
                } catch (\Throwable $exception) {
                    Log::warning('Support inbox message could not be processed.', [
                        'message_number' => $messageNumber,
                        'error' => $exception->getMessage(),
                    ]);
                } finally {
                    // Never leave spoofed or malformed mail unread forever.
                    imap_setflag_full($inbox, (string) $messageNumber, '\\Seen');
                }
            }
        } finally {
            imap_close($inbox);
        }

        return $processed;
    }

    /**
     * Saves a verified inbound message. Kept public so a future webhook
     * receiver can reuse the exact same validation and ticket update path.
     */
    public function ingest(string $from, string $subject, string $body, ?string $messageId = null, array $attachments = [], ?string $headers = null): bool
    {
        $messageId = $messageId ? Str::limit(trim($messageId), 255, '') : null;
        $token = $this->tokenFromReplyHeaders($headers) ?? $this->tokenFromSubject($subject);
        if (! $token || ! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return DB::transaction(function () use ($token, $from, $body, $messageId, $attachments): bool {
            $ticket = SupportTicket::query()->where('reply_token', $token)->lockForUpdate()->first();
            if (! $ticket || ! $ticket->guest_email || ! hash_equals(strtolower($ticket->guest_email), strtolower($from))) {
                return false;
            }
            if ($messageId && SupportMessage::query()->where('inbound_message_id', $messageId)->exists()) {
                return true;
            }

            $text = $this->stripQuotedText($body);
            if ($text === '') {
                return false;
            }
            $uploadedAttachments = $this->storeAttachments($attachments);

            $ticket->messages()->create([
                'author_type' => 'guest',
                'author_id' => 0,
                'body' => $text,
                'attachments' => $uploadedAttachments,
                'inbound_message_id' => $messageId,
            ]);
            $ticket->update(['status' => 'open', 'resolved_at' => null, 'store_read_at' => null]);
            app(ConsoleNotificationService::class)->support($ticket->fresh('order:id,order_number'), 'reply');

            return true;
        });
    }

    private function tokenFromSubject(string $subject): ?string
    {
        return preg_match('/\\[SUP-[0-9]+-([a-z0-9]{24,64})\\]/i', $subject, $matches)
            ? strtolower($matches[1])
            : null;
    }

    private function tokenFromReplyHeaders(?string $headers): ?string
    {
        if (blank($headers)) {
            return null;
        }

        // Unfold continuation lines, then inspect only threading headers. A
        // reply client normally copies our Message-ID into one or both.
        $headers = preg_replace("/\r?\n[ \t]+/", ' ', $headers) ?? $headers;
        preg_match_all('/^(?:in-reply-to|references):\s*(.+)$/mi', $headers, $matches);

        foreach ($matches[1] ?? [] as $value) {
            if (preg_match('/<sup-([a-z0-9]{24,64})-[a-z0-9-]+@[^>\s]+>/i', $value, $token)) {
                return strtolower($token[1]);
            }
        }

        return null;
    }

    private function stripQuotedText(string $body): string
    {
        $original = trim(str_replace("\r\n", "\n", $body));
        $lines = preg_split('/\\r?\\n/', $original) ?: [];
        $kept = [];

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            // Yandex can append its separator to the guest's final sentence.
            if (preg_match('/^(.*?)-{8,}\\s*$/u', $trimmed, $matches)) {
                if (trim($matches[1]) !== '') $kept[] = rtrim($matches[1]);
                break;
            }
            if ($index > 0 && ($this->isQuoteAttribution($lines, $index)
                || $this->isQuoteSeparator($trimmed)
                || $this->isYandexHeader($trimmed)
                || $this->isForwardedHeaderBlock($lines, $index)
                || $trimmed === '--')) {
                break;
            }
            if (str_starts_with(ltrim($line), '>')) continue;
            $kept[] = rtrim($line);
        }

        while ($kept !== [] && (trim((string) end($kept)) === '' || $this->isMobileSignoff((string) end($kept)))) {
            array_pop($kept);
        }

        $cleaned = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $kept)) ?? '');

        // A malformed reply must never turn into an empty ticket message.
        return $cleaned !== '' ? $cleaned : $original;
    }

    private function isQuoteAttribution(array $lines, int $index): bool
    {
        $parts = [];
        for ($offset = 0; $offset < 3 && isset($lines[$index + $offset]); $offset++) {
            $part = trim((string) $lines[$index + $offset]);
            if ($part === '') break;
            $parts[] = $part;
            $attribution = implode(' ', $parts);
            if (str_contains(strtolower($attribution), 'nexf') && $this->hasQuoteAttributionSuffix($attribution)) {
                return true;
            }
        }
        $attribution = implode(' ', $parts);
        if (! str_contains(strtolower($attribution), 'nexf')) return false;

        return preg_match('/(?:wrote|schrieb|a\\s+écrit|escribió|пишет|লিখেছেন)\\s*:\\s*$/iu', $attribution) === 1
            || preg_match('/>\\s*:\\s*$/u', $attribution) === 1;
    }

    private function hasQuoteAttributionSuffix(string $attribution): bool
    {
        return preg_match('/(?:wrote|schrieb|a\\s+écrit|escribió|пишет|লিখেছেন)\\s*:\\s*$/iu', $attribution) === 1
            || preg_match('/>\\s*:\\s*$/u', $attribution) === 1;
    }

    private function isQuoteSeparator(string $line): bool
    {
        return preg_match('/^-{5,}\\s*(?:original\\s+message)?\\s*-*$/iu', $line) === 1
            || preg_match('/^_{10,}\\s*$/u', $line) === 1;
    }

    private function isYandexHeader(string $line): bool
    {
        return preg_match('/^to:\\s*.*@.*;\\s*$/iu', $line) === 1;
    }

    private function isForwardedHeaderBlock(array $lines, int $index): bool
    {
        $line = trim((string) $lines[$index]);
        if (preg_match('/^(?:from|de|von|от)\\s*:\\s*.*@/iu', $line) !== 1) return false;

        for ($offset = 1; $offset <= 3 && isset($lines[$index + $offset]); $offset++) {
            if (preg_match('/^(?:sent|date|to|subject|envoyé|gesendet|betreff|отправлено|кому|тема)\\s*:/iu', trim((string) $lines[$index + $offset])) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isMobileSignoff(string $line): bool
    {
        $line = preg_replace('/\\[([^\\]]+)\\]\\([^)]*\\)/u', '$1', trim($line)) ?? trim($line);

        return preg_match('/^(?:sent\\s+from\\s+(?:my\\s+)?(?:iphone|ipad|android|galaxy|yahoo|aol|outlook)|get\\s+outlook\\s+for|sent\\s+with\\s+proton\\s+mail|envoyé\\s+depuis|gesendet\\s+(?:von|mit)|enviado\\s+desde|отправлено\\s+из|отправлено\\s+с)/iu', $line) === 1;
    }

    private function fromAddress(object $header): string
    {
        $from = $header->from[0] ?? null;
        if (! $from || empty($from->mailbox) || empty($from->host)) {
            return '';
        }

        return strtolower($from->mailbox.'@'.$from->host);
    }

    private function decodeHeader(string $value): string
    {
        return function_exists('imap_utf8') ? imap_utf8($value) : $value;
    }

    private function message($inbox, int $messageNumber): array
    {
        $structure = imap_fetchstructure($inbox, $messageNumber);
        $part = $structure ? $this->findTextPart($structure) : null;
        $raw = $part && $part['number'] !== '' ? imap_fetchbody($inbox, $messageNumber, $part['number']) : imap_body($inbox, $messageNumber);
        $encoding = $part['encoding'] ?? ($structure->encoding ?? 0);
        $text = match ($encoding) {
            3 => base64_decode($raw, true) ?: '',
            4 => quoted_printable_decode($raw),
            default => $raw,
        };

        $attachments = [];
        foreach ($structure ? $this->findAttachmentParts($structure) : [] as $attachment) {
            if (count($attachments) >= 6) {
                break;
            }
            $contents = $this->decodePart(
                imap_fetchbody($inbox, $messageNumber, $attachment['number']),
                $attachment['encoding'],
            );
            if ($contents === '' || strlen($contents) > 20 * 1024 * 1024) {
                continue;
            }
            $actualMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: '';
            if (! in_array($actualMime, self::ATTACHMENT_MIMES, true)) {
                continue;
            }
            $attachments[] = ['name' => $attachment['name'], 'mime' => $actualMime, 'contents' => $contents];
        }

        return [
            'body' => ($part['html'] ?? false) ? $this->htmlToText($text) : trim($text),
            'attachments' => $attachments,
        ];
    }

    private function htmlToText(string $html): string
    {
        // Reply clients generally place the old thread in a blockquote. Remove
        // it before preserving structural line breaks from the new message.
        $html = preg_replace('/<blockquote\\b[^>]*>.*?<\\/blockquote>/is', '', $html) ?? $html;
        $html = preg_replace('/<(?:br\\s*\\/?|\\/(?:p|div|li|tr)|hr\\s*\\/?)\\s*>/i', "\n", $html) ?? $html;

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }

    private function findTextPart(object $part, string $prefix = ''): ?array
    {
        if ($this->isAttachment($part)) {
            return null;
        }
        if (($part->type ?? null) === 0) {
            return ['number' => $prefix, 'encoding' => $part->encoding ?? 0, 'html' => strtoupper($part->subtype ?? 'PLAIN') === 'HTML'];
        }
        foreach ($part->parts ?? [] as $index => $child) {
            $number = $prefix === '' ? (string) ($index + 1) : $prefix.'.'.($index + 1);
            $found = $this->findTextPart($child, $number);
            if ($found && ! $found['html']) {
                return $found;
            }
        }
        foreach ($part->parts ?? [] as $index => $child) {
            $number = $prefix === '' ? (string) ($index + 1) : $prefix.'.'.($index + 1);
            if ($found = $this->findTextPart($child, $number)) {
                return $found;
            }
        }

        return null;
    }

    private function findAttachmentParts(object $part, string $prefix = ''): array
    {
        if ($this->isAttachment($part) && $prefix !== '') {
            return [[
                'number' => $prefix,
                'name' => $this->partName($part) ?? 'attachment',
                'encoding' => $part->encoding ?? 0,
            ]];
        }

        $parts = [];
        foreach ($part->parts ?? [] as $index => $child) {
            $number = $prefix === '' ? (string) ($index + 1) : $prefix.'.'.($index + 1);
            $parts = [...$parts, ...$this->findAttachmentParts($child, $number)];
        }

        return $parts;
    }

    private function isAttachment(object $part): bool
    {
        return strtolower((string) ($part->disposition ?? '')) === 'attachment'
            || $this->partName($part) !== null;
    }

    private function partName(object $part): ?string
    {
        foreach (array_merge($part->dparameters ?? [], $part->parameters ?? []) as $parameter) {
            if (in_array(strtolower((string) ($parameter->attribute ?? '')), ['filename', 'name'], true) && isset($parameter->value)) {
                return $this->decodeHeader((string) $parameter->value);
            }
        }

        return null;
    }

    private function decodePart(string $raw, int $encoding): string
    {
        return match ($encoding) {
            3 => base64_decode($raw, true) ?: '',
            4 => quoted_printable_decode($raw),
            default => $raw,
        };
    }

    private function storeAttachments(array $attachments): array
    {
        return collect($attachments)
            ->take(6)
            ->filter(fn ($attachment) => is_array($attachment)
                && is_string($attachment['contents'] ?? null)
                && strlen($attachment['contents']) <= 20 * 1024 * 1024
                && in_array($attachment['mime'] ?? null, self::ATTACHMENT_MIMES, true))
            ->map(fn ($attachment) => $this->uploads->uploadContents($attachment['contents'], $attachment['mime'], 'support'))
            ->values()
            ->all();
    }
}
