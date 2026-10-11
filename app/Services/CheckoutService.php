<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\ShippingMethod;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(
        private InventoryService $inventory,
        private StoreShippingService $storeShipping,
        private FraudGuardService $fraudGuard,
    ) {}

    public function preview(?Customer $customer, array $data): array
    {
        $paymentMethod = PaymentMethod::query()->active()->find($data['payment_method_id']);
        $shippingMethod = ShippingMethod::query()->whereNull('seller_id')->where('is_store_option',false)->active()->find($data['shipping_method_id']);
        if (!$paymentMethod || !$shippingMethod) {
            throw ValidationException::withMessages(['checkout' => ['Selected payment or shipping method is unavailable.']]);
        }

        $items = [];
        $subtotal = 0.0;
        foreach ($data['items'] as $itemData) {
            $product = Product::query()->with(['seller', 'collections:id'])->availableForSale()->find($itemData['product_id']);
            if (!$product || ($product->seller_id && (!$product->seller || $product->seller->status !== 'approved' || !$product->seller->is_active))) {
                throw ValidationException::withMessages(['items' => ['One or more products are unavailable.']]);
            }
            $variationId = $itemData['variation_id'] ?? null;
            if ($product->product_type === 'variable' && !$variationId) throw ValidationException::withMessages(['items' => ["A variation must be selected for {$product->name}."]]);
            if ($product->product_type === 'simple' && $variationId) throw ValidationException::withMessages(['items' => ["{$product->name} does not have purchasable variations."]]);
            $variation = $variationId ? ProductVariation::query()->where('product_id', $product->id)->where('is_active', true)->find($variationId) : null;
            if ($variationId && !$variation) throw ValidationException::withMessages(['items' => ["Selected variation for {$product->name} is unavailable."]]);
            $quantity = (int) $itemData['quantity'];
            $quote = $variation ? $this->inventory->previewVariation($variation, $quantity) : $this->inventory->previewProduct($product, $quantity);
            $subtotal = round($subtotal + $quote['subtotal'], 2);
            $items[] = ['product' => $product, 'variation' => $variation, 'quantity' => $quantity, 'quote' => $quote];
        }

        $shipping = $this->storeShipping->quote(array_map(fn ($item) => [
            'product' => $item['product'],
            'subtotal' => $item['quote']['subtotal'],
        ], $items), $shippingMethod, $data['store_shipping_methods'] ?? []);
        $coupon = $this->resolveCoupon($data['coupon_code'] ?? null, $subtotal, $customer, $items, isset($data['exclude_order_id']) ? (int) $data['exclude_order_id'] : null);
        $discount = $coupon ? $this->discountForCart($coupon, $items, $subtotal, (float) $shipping['total']) : 0.0;
        $total = round($subtotal + $shipping['total'] - $discount, 2);
        if ($paymentMethod->code === 'wallet') {
            if (!$customer) throw ValidationException::withMessages(['payment_method_id' => ['NEXF Balance is available only to signed-in customers.']]);
            if (app(WalletBalanceService::class)->balance($customer->id) < $total) {
                throw ValidationException::withMessages(['payment_method_id' => ['Your NEXF Balance is not enough for this order.']]);
            }
        }
        $this->fraudGuard->assertAllowed($customer, $data + ['payment_method_code' => $paymentMethod->code], $total);

        return compact('paymentMethod', 'shippingMethod', 'items', 'coupon', 'subtotal') + [
            'discount_total' => $discount,
            'shipping_groups' => $shipping['groups'],
            'shipping_charge' => $shipping['total'],
            'total' => $total,
        ];
    }

    private function resolveCoupon(?string $couponCode, float $subtotal, ?Customer $customer, array $items, ?int $excludeOrderId = null): ?Coupon
    {
        $coupon = $couponCode ? Coupon::where('code', preg_replace('/[^A-Z0-9_-]/', '', Str::upper($couponCode)))->first() : Coupon::query()->where('is_automatic', true)->get()->first(fn (Coupon $candidate) => !$candidate->unusableReason() && $this->couponMatches($candidate, $items));
        if (!$coupon && !$couponCode) return null;
        if (!$coupon) throw ValidationException::withMessages(['coupon_code' => ['Coupon code was not found.']]);
        if ($reason = $coupon->unusableReason()) throw ValidationException::withMessages(['coupon_code' => [$reason]]);
        if ($coupon->minimum_order_amount !== null && $subtotal < (float) $coupon->minimum_order_amount) throw ValidationException::withMessages(['coupon_code' => ['Minimum order amount not reached for this coupon.']]);
        if ($customer && $coupon->per_customer_limit !== null && CouponRedemption::where('coupon_id', $coupon->id)->where('customer_id', $customer->id)->when($excludeOrderId, fn ($query) => $query->where('order_id', '!=', $excludeOrderId))->count() >= $coupon->per_customer_limit) throw ValidationException::withMessages(['coupon_code' => ['Coupon usage limit reached for this customer.']]);
        if (!$this->couponMatches($coupon, $items)) throw ValidationException::withMessages(['coupon_code' => ['This discount does not apply to the items in your cart.']]);
        return $coupon;
    }

    private function couponMatches(Coupon $coupon, array $items): bool
    {
        $quantity = array_sum(array_map(fn ($item) => $item['quantity'], $items));
        if ($coupon->minimum_quantity !== null && $quantity < $coupon->minimum_quantity) return false;
        $products = array_map(fn ($item) => $item['product'], $items);
        if ($coupon->seller_id && !collect($products)->contains(fn ($product) => (int) $product->seller_id === (int) $coupon->seller_id)) return false;
        if ($coupon->discount_kind === 'bxgy') {
            $buyItems = $this->selectedItems($items, $coupon->buy_product_ids, $coupon->buy_category_ids, $coupon->buy_collection_ids);
            $getItems = $this->selectedItems($items, $coupon->eligible_product_ids, $coupon->eligible_category_ids, $coupon->eligible_collection_ids);
            return array_sum(array_column($buyItems, 'quantity')) >= ($coupon->buy_quantity ?? 1) && count($getItems) > 0;
        }
        if (in_array($coupon->discount_kind, ['products', 'order'], true) || $coupon->applies_to === 'product') return count($this->selectedItems($items, $coupon->eligible_product_ids, $coupon->eligible_category_ids, $coupon->eligible_collection_ids)) > 0;
        return true;
    }

    private function discountForCart(Coupon $coupon, array $items, float $subtotal, float $shipping): float
    {
        if ($coupon->discount_kind === 'shipping' || $coupon->applies_to === 'shipping') return min($shipping, $coupon->discountForSubtotal($shipping));
        if ($coupon->discount_kind === 'bxgy') {
            $selected = $this->selectedItems($items, $coupon->eligible_product_ids, $coupon->eligible_category_ids, $coupon->eligible_collection_ids);
            $quantity = min(array_sum(array_column($selected, 'quantity')), $coupon->get_quantity ?? PHP_INT_MAX);
            $available = collect($selected)->sortBy(fn ($item) => $item['quote']['subtotal'] / $item['quantity'])->reduce(function (float $sum, $item) use (&$quantity) { $used=min($quantity,$item['quantity']); $quantity-=$used; return $sum + (($item['quote']['subtotal'] / $item['quantity']) * $used); }, 0.0);
            if ($coupon->reward_type === 'free') return round($available, 2);
            return min($available, $coupon->discountForSubtotal($available));
        }
        $base = $coupon->discount_kind === 'products' || $coupon->applies_to === 'product' ? array_sum(array_map(fn ($item) => $item['quote']['subtotal'], $this->selectedItems($items, $coupon->eligible_product_ids, $coupon->eligible_category_ids, $coupon->eligible_collection_ids))) : $subtotal;
        return $coupon->discountForSubtotal((float) $base);
    }

    private function selectedItems(array $items, ?array $productIds, ?array $categoryIds, ?array $collectionIds): array
    {
        if (!$productIds && !$categoryIds && !$collectionIds) return $items;
        return array_values(array_filter($items, function ($item) use ($productIds, $categoryIds, $collectionIds) {
            $product = $item['product'];
            return ($productIds && in_array($product->id, $productIds, true)) || ($categoryIds && in_array($product->category_id, $categoryIds, true)) || ($collectionIds && $product->collections->contains(fn ($collection) => in_array($collection->id, $collectionIds, true)));
        }));
    }
}
