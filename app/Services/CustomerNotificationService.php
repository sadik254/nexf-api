<?php

namespace App\Services;

use App\Models\CustomerInSiteNotification;
use App\Models\CustomerWalletTransaction;
use App\Models\ReturnRequest;
use App\Models\SupportTicket;
use App\Models\WithdrawalRequest;

/** Shopper bell events that are not part of the ordinary order lifecycle. */
class CustomerNotificationService
{
    public function returnUpdated(ReturnRequest $return): void
    {
        if (!$return->customer_id) return;
        $return->loadMissing('order:id,order_number', 'exchangeOrder:id,order_number');
        $label = $return->type === 'exchange' ? 'Exchange' : 'Return';
        $note = $return->decision_note ? ' '.$return->decision_note : '';
        [$type, $title, $body] = match ($return->status) {
            'refunded' => ['refund_completed', "Refund for {$return->reference} completed", $this->money($return->refund_amount).($return->refund_to === 'balance' ? ' refunded to your NEXF Balance.' : ' refunded to your original payment method.')],
            'exchanged' => ['exchange_exchanged', "Exchange {$return->reference} completed", 'Your replacement'.($return->exchangeOrder ? " ({$return->exchangeOrder->order_number})" : '').' was delivered.'],
            'approved' => [strtolower($label).'_approved', "{$label} {$return->reference} approved", 'We approved your request.'.$note],
            'rejected' => [strtolower($label).'_rejected', "{$label} {$return->reference} declined", "We couldn't accept this request.".$note],
            'returning' => [strtolower($label).'_returning', "{$label} {$return->reference} pickup arranged", "We're arranging the return of your item."],
            'in_transit' => [strtolower($label).'_in_transit', "{$label} {$return->reference} on its way back", 'Your item is on its way back to us'.($return->return_tracking ? " (tracking {$return->return_tracking})." : '.')],
            'received' => [strtolower($label).'_received', "{$label} {$return->reference} item received", 'We received your returned item.'],
            'cancelled' => [strtolower($label).'_cancelled', "{$label} {$return->reference} cancelled", 'This request was cancelled.'.$note],
            default => [null, null, null],
        };
        if (!$type) return;
        $this->send($return->customer_id, $type, $title, $body, ['return_reference' => $return->reference, 'order_number' => $return->order?->order_number, 'url' => '/account/returns']);
    }

    public function supportReply(SupportTicket $ticket): void { $this->support($ticket, 'support_reply', "New reply on {$ticket->reference}"); }
    public function supportResolved(SupportTicket $ticket): void { $this->support($ticket, 'support_resolved', "Ticket {$ticket->reference} resolved"); }

    public function withdrawal(WithdrawalRequest $withdrawal): void
    {
        $amount = $this->money($withdrawal->amount);
        [$type, $title, $body] = $withdrawal->status === 'paid'
            ? ['withdraw_paid', "Withdrawal {$withdrawal->reference} paid", "{$amount} sent".($withdrawal->transaction_id ? " (transaction {$withdrawal->transaction_id})." : '.')]
            : ['withdraw_rejected', "Withdrawal {$withdrawal->reference} declined", "{$amount} is back in your NEXF Balance.".($withdrawal->admin_note ? ' '.$withdrawal->admin_note : '')];
        $this->send($withdrawal->customer_id, $type, $title, $body, ['reference' => $withdrawal->reference, 'url' => '/account/balance']);
    }

    /** Rewards and cancellation credits; completed return refunds have their own event. */
    public function walletCredit(CustomerWalletTransaction $transaction): void
    {
        [$title, $body] = match ($transaction->kind) {
            'cashback' => ['Cashback added', $this->money($transaction->amount).' cashback added to your NEXF Balance.'],
            'review_reward' => ['Review reward added', $this->money($transaction->amount).' added to your NEXF Balance for your approved review.'],
            'referral_reward' => ['Referral reward added', $this->money($transaction->amount).' added to your NEXF Balance: someone you invited got their first order.'],
            'cancellation_credit' => ['Balance returned', $this->money($transaction->amount).' from your cancelled order is back in your NEXF Balance.'],
            default => [null, null],
        };
        if (!$title) return;
        $transaction->loadMissing('order:id,order_number');
        $this->send($transaction->customer_id, 'wallet_'.$transaction->kind, $title, $body, ['order_number' => $transaction->order?->order_number, 'url' => '/account/balance']);
    }

    private function support(SupportTicket $ticket, string $type, string $title): void
    {
        if (!$ticket->customer_id) return;
        $ticket->loadMissing('order:id,order_number');
        $this->send($ticket->customer_id, $type, $title, $ticket->subject, ['ticket_reference' => $ticket->reference, 'order_number' => $ticket->order?->order_number, 'url' => '/account/support']);
    }

    private function send(int $customerId, string $type, string $title, ?string $body, array $data): void
    {
        CustomerInSiteNotification::create(['customer_id' => $customerId, 'type' => $type, 'title' => $title, 'body' => $body, 'data' => array_filter($data, fn ($value) => $value !== null)]);
    }

    private function money(mixed $amount): string
    {
        $amount = (float) $amount;
        return '৳'.number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2);
    }
}
