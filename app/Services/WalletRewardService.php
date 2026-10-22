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
    private const DEFAULTS = ['cashback_percent' => 2.0, 'review_amount' => 20.0, 'referral_amount' => 50.0];

    public function orderDelivered(Order $order): void
    {
        if (!$order->customer_id || !in_array($order->status, ['delivered', 'completed'], true)) return;
        $order->loadMissing('customer.referrer');
        $customer = $order->customer;
        if (!$customer) return;
        $rewards = $this->rewards();
        app(WalletLedgerService::class)->cashback($customer, $order, round((float) $order->total * $rewards['cashback_percent'] / 100, 2));

        // The referrer earns once, when their referred shopper completes the
        // shopper's first delivered order. The ledger source key makes retries safe.
        if ($customer->referred_by_customer_id && $customer->referrer) {
            $firstDelivered = Order::query()->where('customer_id', $customer->id)->whereIn('status', ['delivered', 'completed'])->orderBy('id')->value('id');
            if ((int) $firstDelivered === (int) $order->id) {
                app(WalletLedgerService::class)->referralReward($customer->referrer, $order, $rewards['referral_amount']);
            }
        }
    }

    public function reviewApproved(Review $review): void
    {
        $review->loadMissing('customer', 'orderItem.order');
        app(WalletLedgerService::class)->reviewReward($review->customer, $review, $this->rewards()['review_amount']);
    }

    /** SiteSetting `wallet_rewards` may override the deliberately modest defaults. */
    private function rewards(): array
    {
        $saved = SiteSetting::find('wallet_rewards')?->payload ?? [];
        return [
            'cashback_percent' => max(0, min(100, (float) ($saved['cashback_percent'] ?? self::DEFAULTS['cashback_percent']))),
            'review_amount' => max(0, (float) ($saved['review_amount'] ?? self::DEFAULTS['review_amount'])),
            'referral_amount' => max(0, (float) ($saved['referral_amount'] ?? self::DEFAULTS['referral_amount'])),
        ];
    }
}
