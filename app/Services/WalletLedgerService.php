<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Order;
use App\Models\OrderStoreGroup;
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
        // Parcels a store cancelled earlier already gave their share back.
        $reward = round($reward - $this->parcelCreditsIn($order, 'reward'), 2);
        $withdrawable = round($withdrawable - $this->parcelCreditsIn($order, 'withdrawable'), 2);
        $first = $this->credit($order->customer, $order, 'cancellation_credit', $reward, "Cancellation credit for order {$order->order_number}", "order:{$order->id}:cancellation:reward", 'reward');
        $second = $this->credit($order->customer, $order, 'cancellation_credit', $withdrawable, "Cancellation credit for order {$order->order_number}", "order:{$order->id}:cancellation:withdrawable", 'withdrawable');
        return $first ?? $second;
    }

    /**
     * A store cancelled one parcel: what was prepaid for it goes to the
     * shopper's NEXF Balance. Like a refund, money the shopper paid in
     * (withdrawable Balance, a received bKash / Nagad / Rocket / bank payment)
     * comes back as withdrawable first; the rest is reward credit.
     */
    public function parcelCancellationCredit(Order $order, OrderStoreGroup $group): ?CustomerWalletTransaction
    {
        $amount = round((float) $group->prepaid_amount, 2);
        if ($amount <= 0 || !$order->customer_id) return null;
        $manualPaid = in_array($order->payment_method_code, ['bkash', 'nagad', 'rocket', 'upay', 'bank'], true) && $order->payment_received_at
            ? min((float) ($order->paid_amount ?? 0), max(0, (float) $order->total - (float) ($order->wallet_amount ?? 0)))
            : 0.0;
        $paidIn = (float) ($order->wallet_withdrawable_amount ?? 0) + $manualPaid;
        $withdrawable = round(min($amount, max(0, $paidIn - $this->parcelCreditsIn($order, 'withdrawable'))), 2);
        $description = "Cancellation credit for parcel {$group->number}";
        $first = $this->credit($order->customer, $order, 'cancellation_credit', round($amount - $withdrawable, 2), $description, "order:{$order->id}:parcel:{$group->id}:cancellation:reward", 'reward');
        $second = $this->credit($order->customer, $order, 'cancellation_credit', $withdrawable, $description, "order:{$order->id}:parcel:{$group->id}:cancellation:withdrawable", 'withdrawable');
        return $first ?? $second;
    }

    /** What cancelled parcels of this order have already credited. */
    public function parcelCredits(Order $order): float
    {
        return (float) array_sum($this->creditedParcels($order));
    }

    /** Parcel id => what its cancellation credited, for this order's store-cancelled parcels. */
    public function creditedParcels(Order $order): array
    {
        $credited = [];
        foreach ($this->parcelCreditRows($order)->get(['source_key', 'amount']) as $row) {
            $parcel = (int) explode(':', $row->source_key)[3];
            $credited[$parcel] = round(($credited[$parcel] ?? 0) + (float) $row->amount, 2);
        }
        return $credited;
    }

    private function parcelCreditsIn(Order $order, string $bucket): float
    {
        return (float) $this->parcelCreditRows($order)->where('bucket', $bucket)->sum('amount');
    }

    private function parcelCreditRows(Order $order)
    {
        return CustomerWalletTransaction::query()->where('order_id', $order->id)->where('kind', 'cancellation_credit')
            ->where('source_key', 'like', "order:{$order->id}:parcel:%");
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
