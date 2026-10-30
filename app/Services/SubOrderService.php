<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\DeliveryGroup;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStoreGroup;
use App\Models\Seller;

/**
 * Keeps an order's store groups working as seller sub-orders.
 *
 * - Every line belongs to the group of the store that ships it (a shared
 *   delivery group ships from its main store).
 * - A group is numbered `{order}-{store SKU code}`, e.g. NEXF-00024-SADK; the
 *   house store's group ends in -NEXF. That number is the courier invoice.
 * - The order's discount and prepayment are split across groups, so each
 *   parcel's COD is exactly what the customer still owes for it.
 */
class SubOrderService
{
    private const RANK = ['pending' => 0, 'confirmed' => 1, 'packed' => 2, 'shipped' => 3, 'delivered' => 4, 'return_pending' => 5, 'returned' => 6];

    /** Link lines, number groups, split money and derive statuses. */
    public function sync(Order $order): void
    {
        $order->unsetRelation('storeGroups');
        $order->unsetRelation('items');
        $this->link($order);
        $this->allocate($order);
        foreach ($order->storeGroups()->get() as $group) $this->refreshStatus($group);
    }

    /** For orders placed before sub-orders: sync, then lift each parcel's courier details onto its group. */
    public function backfill(Order $order): void
    {
        $this->sync($order);
        foreach ($order->storeGroups()->with('items')->get() as $group) {
            $tracked = $group->items->first(fn (OrderItem $item) => $item->tracking_number || $item->courier_consignment_id);
            if (!$tracked) continue;
            $group->update([
                'courier_provider' => $tracked->courier_provider,
                'tracking_number' => $tracked->tracking_number,
                'courier_tracking_url' => $tracked->courier_tracking_url,
                'courier_consignment_id' => $tracked->courier_consignment_id,
                'courier_invoice' => $tracked->courier_invoice,
                'courier_status' => $tracked->courier_status,
            ]);
        }
    }

    public function link(Order $order): void
    {
        $groups = $order->storeGroups()->get();
        if ($groups->isEmpty()) return;
        $deliveryGroups = null;
        $sellers = Seller::query()->whereIn('id', $groups->pluck('seller_id')->filter())->get(['id', 'sku_prefix'])->keyBy('id');

        foreach ($order->items()->get() as $item) {
            $group = $groups->first(fn (OrderStoreGroup $g) => $g->seller_id === $item->seller_id);
            if (!$group) {
                // The store is in a shared delivery group: its lines ride with the main store's parcel.
                $deliveryGroups ??= DeliveryGroup::query()->get();
                $shared = $deliveryGroups->first(fn (DeliveryGroup $d) => $item->seller_id === null ? $d->includes_house : in_array((int) $item->seller_id, array_map('intval', $d->seller_ids ?? []), true));
                $group = $shared ? $groups->first(fn (OrderStoreGroup $g) => $g->seller_id === ($shared->ships_from_seller_id === null ? null : (int) $shared->ships_from_seller_id)) : null;
            }
            $group ??= $groups->first();
            if ((int) $item->store_group_id !== (int) $group->id) $item->update(['store_group_id' => $group->id]);
        }

        foreach ($groups as $group) {
            $code = $group->seller_id === null ? 'NEXF' : $sellers->get($group->seller_id)?->sku_prefix;
            $suffix = is_string($code) && preg_match('/^[A-Z0-9]{4}$/', $code) ? $code : 'S' . $group->seller_id;
            $group->update([
                'number' => $group->number ?? "{$order->order_number}-{$suffix}",
                'ships_by' => $group->seller_id === null ? 'nexf' : 'seller',
            ]);
        }
    }

    /**
     * Split the discount and the prepayment across groups. A store's own coupon
     * comes off that store's group and is funded by the store. Every other
     * discount, and the prepayment (NEXF Balance, money already paid), comes off
     * the parcels NEXF ships, by value; only what does not fit there spills onto
     * the stores' parcels, and NEXF pays that back through their payouts. A
     * group already handed to a courier keeps its COD - the courier is already
     * collecting it.
     */
    public function allocate(Order $order): void
    {
        $groups = $order->storeGroups()->with('items')->orderBy('id')->get();
        if ($groups->isEmpty()) return;
        $subtotals = $groups->mapWithKeys(fn (OrderStoreGroup $g) => [$g->id => round((float) $g->items->sum(fn (OrderItem $i) => (float) $i->line_subtotal), 2)]);

        // A store's edit discount stays on its own parcel, at its own cost.
        $sellerFunded = $groups->mapWithKeys(fn (OrderStoreGroup $g) => [$g->id => (float) $g->manual_discount])->all();
        $storeFundedTotal = (float) array_sum($sellerFunded);
        $redemptions = $order->couponRedemptions()->get();
        $coupons = Coupon::query()->whereIn('id', $redemptions->pluck('coupon_id')->filter())->get(['id', 'seller_id'])->keyBy('id');
        foreach ($redemptions as $redemption) {
            $sellerId = $coupons->get($redemption->coupon_id)?->seller_id;
            if (!$sellerId) continue;
            // Spread over the groups carrying that store's lines, by their value.
            $weights = $groups->mapWithKeys(fn (OrderStoreGroup $g) => [$g->id => (float) $g->items->where('seller_id', $sellerId)->sum(fn (OrderItem $i) => (float) $i->line_subtotal)])->filter(fn ($w) => $w > 0);
            foreach ($this->spread((float) $redemption->discount_amount, $weights->all()) as $id => $share) $sellerFunded[$id] += $share;
            $storeFundedTotal += (float) $redemption->discount_amount;
        }

        $nexfIds = $groups->where('ships_by', 'nexf')->pluck('id')->all();
        $platformDiscount = max(0, round((float) $order->discount_total - $storeFundedTotal, 2));
        $room = $groups->mapWithKeys(fn (OrderStoreGroup $g) => [$g->id => max(0, round($subtotals[$g->id] - $sellerFunded[$g->id], 2))])->all();
        $platformShares = $this->place($platformDiscount, $room, $nexfIds);

        $totals = [];
        foreach ($groups as $group) {
            $discount = round(min($subtotals[$group->id], $sellerFunded[$group->id] + ($platformShares[$group->id] ?? 0)), 2);
            $totals[$group->id] = round($subtotals[$group->id] + (float) $group->shipping_charge - $discount, 2);
            $group->forceFill(['subtotal' => $subtotals[$group->id], 'discount_share' => $discount, 'seller_funded_discount' => round($sellerFunded[$group->id], 2)]);
        }

        $prepaid = $order->payment_status === 'paid'
            ? (float) $order->total
            : min((float) $order->total, (float) $order->wallet_amount + (float) ($order->paid_amount ?? 0));
        // Cancelled parcels collect nothing. There is no parcel-level wallet
        // credit in the ledger yet, so a later cancellation cannot be treated
        // as prepayment for a remaining parcel.
        $credited = [];
        $cancelled = fn (OrderStoreGroup $g) => $g->status === 'cancelled' || isset($credited[$g->id]);
        $prepaidShares = $this->place($prepaid, $groups->mapWithKeys(fn (OrderStoreGroup $g) => [$g->id => $cancelled($g) ? 0.0 : $totals[$g->id]])->all(), $nexfIds);

        foreach ($groups as $group) {
            if ($group->shipped_at === null && $group->courier_consignment_id === null && $cancelled($group)) {
                $group->forceFill(['prepaid_amount' => 0, 'cod_amount' => 0]);
            } elseif ($group->shipped_at === null && $group->courier_consignment_id === null) {
                $share = round(min($totals[$group->id], $prepaidShares[$group->id] ?? 0), 2);
                $group->forceFill(['prepaid_amount' => $share, 'cod_amount' => max(0, round($totals[$group->id] - $share, 2))]);
            }
            $group->save();
        }
    }

    /** A group's status is its slowest live line's, like the order's. */
    public function refreshStatus(OrderStoreGroup $group): void
    {
        $statuses = $group->items()->pluck('fulfillment_status');
        if ($statuses->isEmpty()) return;
        $live = $statuses->reject(fn (string $s) => $s === 'cancelled');
        $status = $live->isEmpty() ? 'cancelled' : ($live->every(fn (string $s) => $s === 'returned') ? 'returned' : $live->sortBy(fn (string $s) => self::RANK[$s] ?? 0)->first());
        $rank = self::RANK[$status] ?? -1;
        $group->update([
            'status' => $status,
            'packed_at' => $rank >= self::RANK['packed'] ? ($group->packed_at ?? now()) : null,
            'shipped_at' => $rank >= self::RANK['shipped'] ? ($group->shipped_at ?? $group->items()->min('shipped_at') ?? now()) : null,
            'delivered_at' => $rank >= self::RANK['delivered'] && $status !== 'returned' ? ($group->delivered_at ?? $group->items()->max('delivered_at') ?? now()) : $group->delivered_at,
        ]);
    }

    /** Steadfast's one-line description of a parcel: "Jacket ×1, Shirt ×2", within 255 characters. */
    public function itemDescription(OrderStoreGroup $group): string
    {
        $lines = $group->items->where('fulfillment_status', '!=', 'cancelled')->map(fn (OrderItem $i) => "{$i->product_name} ×{$i->quantity}")->implode(', ');
        return mb_strimwidth($lines, 0, 255, '…');
    }

    /**
     * Put `amount` on the `first` keys up to their room, by room; whatever does
     * not fit goes on the other keys the same way.
     */
    private function place(float $amount, array $room, array $first): array
    {
        $out = array_map(fn () => 0.0, $room);
        $firstKeys = array_flip($first);
        foreach ([array_intersect_key($room, $firstKeys), array_diff_key($room, $firstKeys)] as $tier) {
            $tier = array_filter($tier, fn ($r) => $r > 0);
            $take = round(min($amount, array_sum($tier)), 2);
            foreach ($this->spread($take, $tier) as $key => $share) $out[$key] = round($out[$key] + $share, 2);
            $amount = round($amount - $take, 2);
        }
        return $out;
    }

    /** Split `amount` across keys by weight; the last key takes the rounding remainder. */
    private function spread(float $amount, array $weights): array
    {
        $sum = array_sum($weights);
        if ($amount <= 0 || $sum <= 0) return array_map(fn () => 0.0, $weights);
        $out = [];
        $left = $amount;
        $keys = array_keys($weights);
        foreach ($keys as $i => $key) {
            $share = $i === count($keys) - 1 ? $left : round($amount * $weights[$key] / $sum, 2);
            $out[$key] = round($share, 2);
            $left = round($left - $share, 2);
        }
        return $out;
    }
}
