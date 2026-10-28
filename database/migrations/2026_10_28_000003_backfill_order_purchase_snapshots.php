<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('order_items')->whereNull('commission_rate')->orderBy('id')->chunkById(200, function ($items): void {
            foreach ($items as $item) {
                $rate = $item->seller_id ? DB::table('sellers')->where('id', $item->seller_id)->value('commission_rate') : null;
                DB::table('order_items')->where('id', $item->id)->update(['commission_rate' => $rate]);
            }
        });
        DB::table('orders')->whereNotNull('reseller_id')->whereNull('reseller_commission_rate')->orderBy('id')->chunkById(200, function ($orders): void {
            foreach ($orders as $order) {
                $reseller = DB::table('resellers')->select('commission_rate', 'monthly_target')->where('id', $order->reseller_id)->first();
                if ($reseller) DB::table('orders')->where('id', $order->id)->update(['reseller_commission_rate' => $reseller->commission_rate, 'reseller_monthly_target' => $reseller->monthly_target]);
            }
        });
        DB::table('coupon_redemptions')->whereNull('coupon_name')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $coupon = $row->coupon_id ? DB::table('coupons')->select('name', 'is_automatic')->where('id', $row->coupon_id)->first() : null;
                DB::table('coupon_redemptions')->where('id', $row->id)->update(['coupon_name' => $coupon?->name ?: $row->coupon_code, 'is_automatic' => (bool) ($coupon?->is_automatic)]);
            }
        });
        DB::table('order_items')->whereNotNull('variation_id')->whereNull('variation_labels')->orderBy('id')->chunkById(100, function ($items): void {
            foreach ($items as $item) {
                $product = $item->product_id ? DB::table('products')->select('option_groups')->where('id', $item->product_id)->first() : null;
                $variation = DB::table('product_variations')->select('attributes', 'image_url')->where('id', $item->variation_id)->first();
                if (!$product || !$variation) continue;
                $attributes = json_decode($variation->attributes ?: '{}', true) ?: [];
                $groups = json_decode($product->option_groups ?: '[]', true) ?: [];
                $labels = [];
                foreach ($groups as $group) {
                    $key = $group['key'] ?? null;
                    if (!$key || !array_key_exists($key, $attributes)) continue;
                    $selected = collect($group['values'] ?? [])->first(fn ($value) => (string) ($value['value'] ?? '') === (string) $attributes[$key]);
                    $labels[] = ['group' => $group['label'] ?? $key, 'value' => $selected['label'] ?? (string) $attributes[$key], 'swatch' => $selected['swatch'] ?? null];
                }
                DB::table('order_items')->where('id', $item->id)->update(['variation_labels' => json_encode($labels), 'variation_image' => $variation->image_url]);
            }
        });
    }

    public function down(): void {}
};
