<?php

namespace App\Services;

use App\Mail\NoticeMail;
use App\Models\ReturnRequest;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Sends the shopper one clear outcome email when a return is completed. */
class ReturnCompletionMailService
{
    public function send(ReturnRequest $return): void
    {
        if (! in_array($return->status, ['refunded', 'exchanged'], true)) return;

        $return->loadMissing([
            'customer:id,name,email',
            'order:id,order_number,shipping_name,shipping_email',
            'exchangeOrder:id,order_number',
            'ticket:id,reference',
        ]);
        $email = $return->customer?->email ?: $return->order?->shipping_email;
        if (! $email) return;

        $name = $return->customer?->name ?: $return->order?->shipping_name ?: 'there';
        $completedAt = $return->completed_at?->timezone('Asia/Dhaka')->format('j M Y, g:i A');
        $returnsUrl = rtrim((string) config('app.frontend_url'), '/').'/account/returns';

        if ($return->status === 'refunded') {
            $amount = $this->money($return->refund_amount);
            $destination = $return->refund_to === 'balance' ? 'NEXF Balance' : 'Original payment method';
            $data = [
                'heading' => 'Your refund is on its way',
                'greeting' => 'Hi '.$this->firstName($name).',',
                'preheader' => "{$amount} refunded for {$return->reference}.",
                'lines' => ["We've refunded {$amount} to your ".($return->refund_to === 'balance' ? 'NEXF Balance.' : 'original payment method.')],
                'rows' => array_filter([
                    'Reference' => $return->reference,
                    'Order' => $return->order?->order_number,
                    'Amount' => $amount,
                    'Refunded to' => $destination,
                    'Date' => $completedAt,
                ]),
                'button' => ['label' => 'View returns', 'url' => $returnsUrl],
            ];
            $subject = "Your refund for {$return->reference} is done";
        } else {
            $replacement = $return->exchangeOrder?->order_number;
            $data = [
                'heading' => 'Your exchange is complete',
                'greeting' => 'Hi '.$this->firstName($name).',',
                'preheader' => "{$return->reference} is complete.",
                'lines' => [$replacement
                    ? "Your replacement {$replacement} was delivered and we've received the original item."
                    : "We've received the original item and your exchange is complete."],
                'rows' => array_filter([
                    'Reference' => $return->reference,
                    'Order' => $return->order?->order_number,
                    'Replacement order' => $replacement,
                    'Completed' => $completedAt,
                ]),
                'button' => ['label' => 'View returns', 'url' => $returnsUrl],
            ];
            $subject = "Your exchange {$return->reference} is complete";
        }

        if ($return->ticket?->reference) {
            $data['footnote'] = "Something not right? Reply on your support ticket {$return->ticket->reference} and it opens again.";
        }

        try {
            Mail::to($email)->send(new NoticeMail($subject, $data));
        } catch (\Throwable $exception) {
            Log::warning('Return completion email failed', [
                'return_request_id' => $return->id,
                'reference' => $return->reference,
                'status' => $return->status,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /** A frontend reply can reopen the just-completed ticket before it resolves it again. */
    public function suppressesRecentTicketResolution(SupportTicket $ticket): bool
    {
        return ReturnRequest::query()
            ->where('support_ticket_id', $ticket->id)
            ->whereIn('status', ['refunded', 'exchanged'])
            ->where('completed_at', '>=', now()->subMinute())
            ->exists();
    }

    private function firstName(string $name): string
    {
        return trim(explode(' ', trim($name))[0]) ?: 'there';
    }

    private function money(mixed $amount): string
    {
        $amount = (float) $amount;
        return '৳'.number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2);
    }
}
