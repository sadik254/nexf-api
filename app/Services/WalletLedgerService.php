<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\Review;

class WalletLedgerService
{
    public function cancellationCredit(Order $order): ?CustomerWalletTransaction
    {
        if (!$order->customer_id) return null;
        $reward = (float) ($order->wallet_reward_amount ?? 0);
        $withdrawable = (float) ($order->wallet_withdrawable_amount ?? 0);
        if ($reward <= 0 && $withdrawable <= 0) {
            $reward = (float) ($order->wallet_amount ?? 0);
            if ($reward <= 0 && $order->payment_method_code === 'wallet') $reward = (float) ($order->paid_amount ?: $order->total);
        }
        $first = $this->credit($order->customer, $order, 'cancellation_credit', $reward, "Cancellation credit for order {$order->order_number}", "order:{$order->id}:cancellation:reward", 'reward');
        $second = $this->credit($order->customer, $order, 'cancellation_credit', $withdrawable, "Cancellation credit for order {$order->order_number}", "order:{$order->id}:cancellation:withdrawable", 'withdrawable');
        return $first ?? $second;
    }

    public function cashback(Customer $customer, Order $order, float $amount): ?CustomerWalletTransaction
    {
        return $this->credit($customer, $order, 'cashback', $amount, "Cashback for order {$order->order_number}", "order:{$order->id}:cashback", 'reward');
    }

    public function reviewReward(Customer $customer, Review $review, float $amount): ?CustomerWalletTransaction
    {
        $order = $review->orderItem?->order;
        return $this->credit($customer, $order, 'review_reward', $amount, 'Reward for an approved review', "review:{$review->id}", 'reward');
    }

    public function referralReward(Customer $customer, Order $order, float $amount): ?CustomerWalletTransaction
    {
        return $this->credit($customer, $order, 'referral_reward', $amount, "Referral reward for order {$order->order_number}", "customer:{$order->customer_id}:referral", 'reward');
    }

    public function refundCredit(ReturnRequest $return, float $amount): void
    {
        if ($amount <= 0 || !$return->customer_id) return;
        $order = $return->order;
        $manualPaid = 0.0;
        if ($order && in_array($order->payment_method_code, ['bkash', 'nagad', 'rocket', 'upay', 'bank'], true) && $order->payment_received_at) {
            $manualPaid = min((float) ($order->paid_amount ?? 0), max(0, (float) $order->total - (float) ($order->wallet_amount ?? 0)));
        }
        $alreadyRefunded = $order ? (float) CustomerWalletTransaction::where('order_id', $order->id)->where('kind', 'refund')->where('bucket', 'withdrawable')->where('type', 'credit')->sum('amount') : 0.0;
        $withdrawable = min($amount, max(0, $manualPaid - $alreadyRefunded));
        $reward = round($amount - $withdrawable, 2);
        $this->credit($return->customer, $order, 'refund', $reward, "Refund for return {$return->reference}", "return:{$return->id}:refund:reward", 'reward', $return->id);
        $this->credit($return->customer, $order, 'refund', $withdrawable, "Refund for return {$return->reference}", "return:{$return->id}:refund:withdrawable", 'withdrawable', $return->id);
    }

    private function credit(?Customer $customer, ?Order $order, string $kind, float $amount, string $description, string $sourceKey, string $bucket, ?int $returnRequestId = null): ?CustomerWalletTransaction
    {
        if (!$customer || $amount <= 0) return null;
        $transaction = CustomerWalletTransaction::firstOrCreate(
            ['customer_id' => $customer->id, 'kind' => $kind, 'source_key' => $sourceKey],
            ['order_id' => $order?->id, 'return_request_id' => $returnRequestId, 'type' => 'credit', 'bucket' => $bucket, 'amount' => $amount, 'description' => $description],
        );
        // A retry returns the existing row, so it cannot repeat the bell event.
        if ($transaction->wasRecentlyCreated) app(CustomerNotificationService::class)->walletCredit($transaction);
        return $transaction;
    }
}
