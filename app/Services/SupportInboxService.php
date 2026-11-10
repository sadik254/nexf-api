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
                    if ($this->ingest($from, $subject, $message['body'], $messageId ?: null, $message['attachments'])) {
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
    public function ingest(string $from, string $subject, string $body, ?string $messageId = null, array $attachments = []): bool
    {
        $messageId = $messageId ? Str::limit(trim($messageId), 255, '') : null;
        $token = $this->tokenFromSubject($subject);
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

    private function stripQuotedText(string $body): string
    {
        $lines = preg_split('/\\r?\\n/', str_replace("\r\n", "\n", $body)) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            if (preg_match('/^\\s*On .+wrote:\\s*$/i', $line) || preg_match('/^\\s*From:\\s+/i', $line) || trim($line) === '--') {
                break;
            }
            if (! str_starts_with(ltrim($line), '>')) {
                $kept[] = rtrim($line);
            }
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $kept)) ?? '');
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
            'body' => ($part['html'] ?? false) ? trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5)) : trim($text),
            'attachments' => $attachments,
        ];
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
