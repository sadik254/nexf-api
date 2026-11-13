<?php

namespace App\Services;

use App\Mail\NoticeMail;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Sends a non-blocking resolution notice after a staff-caused ticket transition. */
class SupportTicketResolvedMailService
{
    public function send(SupportTicket $ticket): void
    {
        $ticket->loadMissing(['customer:id,name,email', 'reseller:id,name,email', 'order:id,order_number']);
        [$email, $name, $kind] = $this->recipient($ticket);
        if (! $email) return;

        $resolvedAt = $ticket->resolved_at?->timezone('Asia/Dhaka')->format('j M Y, g:i A');
        $rows = ['Reference' => $ticket->reference, 'Subject' => $ticket->subject];
        if ($ticket->order?->order_number) $rows['Order'] = $ticket->order->order_number;
        $rows['Resolved'] = $resolvedAt;

        $data = [
            'heading' => 'Your support ticket is resolved',
            'greeting' => 'Hi '.$this->firstName($name).',',
            'preheader' => "We marked {$ticket->reference} as resolved.",
            'rows' => $rows,
        ];
        $replyTo = null;
        $messageId = null;
        if ($kind === 'guest') {
            $token = $ticket->ensureReplyToken();
            $replyTo = (string) config('support.inbox.address');
            $domain = strrchr($replyTo, '@');
            $domain = $domain ? substr($domain, 1) : 'nexflifestyle.com';
            $messageId = "sup-{$token}-resolved@{$domain}";
            $data += [
                'lines' => ['We marked the message you sent from our Contact page as resolved.'],
                'footnote' => 'Still need help? Just reply to this email and the conversation opens again.',
            ];
        } else {
            $reseller = $kind === 'reseller';
            $data += [
                'lines' => ['We marked your support ticket as resolved. If anything is still not right, open the ticket and reopen it, and we will pick it up again.'],
                'button' => ['label' => 'View ticket', 'url' => rtrim((string) config('app.frontend_url'), '/').($reseller ? '/dashboard/support' : '/account/support')],
                'footnote' => 'You can reopen a resolved ticket from your Support page.',
            ];
        }

        try {
            Mail::to($email)->send(new NoticeMail("Your support ticket is resolved [{$ticket->reference}]", $data, $replyTo, $messageId));
        } catch (\Throwable $exception) {
            Log::warning('Support ticket resolution email failed', [
                'ticket_id' => $ticket->id,
                'reference' => $ticket->reference,
                'recipient_type' => $kind,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /** @return array{0:?string,1:string,2:string} */
    private function recipient(SupportTicket $ticket): array
    {
        if ($ticket->customer?->email) return [$ticket->customer->email, $ticket->customer->name, 'customer'];
        if ($ticket->reseller?->email) return [$ticket->reseller->email, $ticket->reseller->name, 'reseller'];
        return [$ticket->guest_email, $ticket->guest_name ?: 'there', 'guest'];
    }

    private function firstName(?string $name): string
    {
        return trim(explode(' ', trim((string) $name))[0]) ?: 'there';
    }
}
