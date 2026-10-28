<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Order;
use App\Models\Review;

class WalletLedgerService
{
    public function cancellationCredit(Order $order): ?CustomerWalletTransaction
    {
        $amount = (float) ($order->wallet_amount ?? 0);
        if ($amount <= 0 && $order->payment_method_code === 'wallet') $amount = (float) ($order->paid_amount ?: $order->total);
        if ($amount <= 0 || !$order->customer_id) return null;
        return $this->credit($order->customer, $order, 'cancellation_credit', $amount, "Cancellation credit for order {$order->order_number}", "order:{$order->id}:cancellation");
    }

    public function cashback(Customer $customer, Order $order, float $amount): ?CustomerWalletTransaction
    {
        return $this->credit($customer, $order, 'cashback', $amount, "Cashback for order {$order->order_number}", "order:{$order->id}:cashback");
    }

    public function reviewReward(Customer $customer, Review $review, float $amount): ?CustomerWalletTransaction
    {
        $order = $review->orderItem?->order;
        return $this->credit($customer, $order, 'review_reward', $amount, 'Reward for an approved review', "review:{$review->id}");
    }

    public function referralReward(Customer $customer, Order $order, float $amount): ?CustomerWalletTransaction
    {
        return $this->credit($customer, $order, 'referral_reward', $amount, "Referral reward for order {$order->order_number}", "customer:{$order->customer_id}:referral");
    }

    private function credit(?Customer $customer, ?Order $order, string $kind, float $amount, string $description, string $sourceKey): ?CustomerWalletTransaction
    {
        if (!$customer || $amount <= 0) return null;
        return CustomerWalletTransaction::firstOrCreate(
            ['customer_id' => $customer->id, 'kind' => $kind, 'source_key' => $sourceKey],
            ['order_id' => $order?->id, 'type' => 'credit', 'amount' => $amount, 'description' => $description],
        );
    }
}
