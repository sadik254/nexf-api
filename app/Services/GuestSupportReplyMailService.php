<?php

namespace App\Services;

use App\Mail\NoticeMail;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class GuestSupportReplyMailService
{
    public function send(SupportTicket $ticket, SupportMessage $reply): void
    {
        if ($ticket->customer_id || ! $ticket->guest_email) {
            return;
        }

        $token = $ticket->ensureReplyToken();
        $firstGuestMessage = $ticket->messages()
            ->where('author_type', 'guest')
            ->oldest('id')
            ->first();
        $attachments = collect($reply->attachments ?? [])
            ->filter(fn ($url) => is_string($url) && filter_var($url, FILTER_VALIDATE_URL))
            ->values()
            ->all();
        $replyAddress = (string) config('support.inbox.address');
        $domain = strrchr($replyAddress, '@');
        $domain = $domain ? substr($domain, 1) : 'nexflifestyle.com';
        // Unique per outgoing email, while retaining the ticket token that a
        // replying mail client returns through In-Reply-To / References.
        $messageId = "sup-{$token}-{$reply->id}@{$domain}";

        try {
            Mail::to($ticket->guest_email)->send(new NoticeMail(
                "Re: {$ticket->subject} ({$ticket->reference})",
                [
                    'heading' => 'We replied to your message',
                    'greeting' => 'Hi '.($ticket->guest_name ?: 'there').',',
                    'lines' => ['Our support team answered the message you sent from our Contact page.'],
                    'quote' => $reply->body,
                    'quoteAttachments' => $attachments,
                    'rows' => [
                        'Reference' => $ticket->reference,
                        'Subject' => $ticket->subject,
                        'Your message' => $firstGuestMessage?->created_at?->timezone('Asia/Dhaka')->format('j M Y, g:i A') ?? $ticket->created_at?->timezone('Asia/Dhaka')->format('j M Y, g:i A'),
                    ],
                    'footnote' => 'To answer, just reply to this email. Your reply goes into the same conversation.',
                ],
                $replyAddress,
                $messageId,
            ));
        } catch (\Throwable $exception) {
            Log::warning('Guest support reply email failed', [
                'ticket_id' => $ticket->id,
                'reference' => $ticket->reference,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
