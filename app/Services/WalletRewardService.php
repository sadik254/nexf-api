<?php
namespace App\Services;

use App\Models\CustomerWalletTransaction;
use App\Models\Order;
use App\Models\Review;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\DB;

/** Awards earned wallet credits at irreversible milestones. */
class WalletRewardService
{
    private const DEFAULTS = [
        'cashback_enabled' => true,
        'cashback_percent' => 2.0,
        'review_enabled' => true,
        'review_amount' => 20.0,
        'referral_enabled' => true,
        'referral_amount' => 50.0,
    ];

    public function orderDelivered(Order $order): void
    {
        if (!$order->customer_id || !in_array($order->status, ['delivered', 'completed'], true)) return;
        $order->loadMissing('customer.referrer');
        $customer = $order->customer;
        if (!$customer) return;
        $rewards = $this->rewards();
        app(WalletLedgerService::class)->cashback(
            $customer,
            $order,
            $rewards['cashback_enabled'] ? round((float) $order->total * $rewards['cashback_percent'] / 100, 2) : 0
        );

        // The referrer earns once, when their referred shopper completes the
        // shopper's first delivered order. The ledger source key makes retries safe.
        if ($customer->referred_by_customer_id && $customer->referrer) {
            $firstDelivered = Order::query()->where('customer_id', $customer->id)->whereIn('status', ['delivered', 'completed'])->orderBy('id')->value('id');
            if ((int) $firstDelivered === (int) $order->id) {
                app(WalletLedgerService::class)->referralReward($customer->referrer, $order, $rewards['referral_enabled'] ? $rewards['referral_amount'] : 0);
            }
        }
    }

    public function reviewApproved(Review $review): void
    {
        $review->loadMissing('customer', 'orderItem.order');
        $rewards = $this->rewards();
        app(WalletLedgerService::class)->reviewReward($review->customer, $review, $rewards['review_enabled'] ? $rewards['review_amount'] : 0);
    }

    /** The console setting may override the deliberately modest defaults. */
    public function rewards(): array
    {
        $saved = SiteSetting::find('wallet_rewards')?->payload ?? [];
        return [
            // Missing flags deliberately mean enabled, preserving the existing
            // behaviour for installations created before this setting existed.
            'cashback_enabled' => array_key_exists('cashback_enabled', $saved) ? (bool) $saved['cashback_enabled'] : self::DEFAULTS['cashback_enabled'],
            'cashback_percent' => max(0, min(100, (float) ($saved['cashback_percent'] ?? self::DEFAULTS['cashback_percent']))),
            'review_enabled' => array_key_exists('review_enabled', $saved) ? (bool) $saved['review_enabled'] : self::DEFAULTS['review_enabled'],
            'review_amount' => max(0, (float) ($saved['review_amount'] ?? self::DEFAULTS['review_amount'])),
            'referral_enabled' => array_key_exists('referral_enabled', $saved) ? (bool) $saved['referral_enabled'] : self::DEFAULTS['referral_enabled'],
            'referral_amount' => max(0, (float) ($saved['referral_amount'] ?? self::DEFAULTS['referral_amount'])),
        ];
    }
}
