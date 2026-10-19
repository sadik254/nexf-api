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
        $shippingMethod = $this->shippingMethodForAddress($data);
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
            $stockRequest = !empty($data['stock_request']);
            $quote = $variation ? $this->inventory->previewVariation($variation, $quantity, $stockRequest) : $this->inventory->previewProduct($product, $quantity, $stockRequest);
            $subtotal = round($subtotal + $quote['subtotal'], 2);
            $items[] = ['product' => $product, 'variation' => $variation, 'quantity' => $quantity, 'quote' => $quote];
        }

        $shipping = $this->storeShipping->quote(array_map(fn ($item) => [
            'product' => $item['product'],
            'subtotal' => $item['quote']['subtotal'],
        ], $items), $shippingMethod, $data['store_shipping_methods'] ?? [], $data['store_delivery_options'] ?? []);
        $coupons = $this->resolveCoupons($data['coupon_code'] ?? null, $subtotal, $customer, $items, isset($data['exclude_order_id']) ? (int) $data['exclude_order_id'] : null);
        $appliedDiscounts = [];
        $remaining = $subtotal + (float) $shipping['total'];
        foreach ($coupons as $coupon) {
            $amount = min($remaining, $this->discountForCart($coupon, $items, $subtotal, (float) $shipping['total']));
            if ($amount <= 0) continue;
            $appliedDiscounts[] = ['coupon' => $coupon, 'amount' => $amount];
            $remaining = round($remaining - $amount, 2);
        }
        $discount = round(array_sum(array_column($appliedDiscounts, 'amount')), 2);
        $total = round($subtotal + $shipping['total'] - $discount, 2);
        if ($paymentMethod->code === 'wallet') {
            if (!$customer) throw ValidationException::withMessages(['payment_method_id' => ['NEXF Balance is available only to signed-in customers.']]);
            if (app(WalletBalanceService::class)->balance($customer->id) < $total) {
                throw ValidationException::withMessages(['payment_method_id' => ['NEXF Balance is not enough for this order.']]);
            }
        }
        $this->fraudGuard->assertAllowed($customer, $data + ['payment_method_code' => $paymentMethod->code], $total);

        return compact('paymentMethod', 'shippingMethod', 'items', 'subtotal') + [
            // Kept for compatibility with existing order persistence.
            'coupon' => $appliedDiscounts[0]['coupon'] ?? null,
            'coupons' => array_map(fn (array $discount) => $discount['coupon'], $appliedDiscounts),
            'applied_discounts' => $appliedDiscounts,
            'discount_total' => $discount,
            'shipping_groups' => $shipping['groups'],
            'shipping_charge' => $shipping['total'],
            'total' => $total,
        ];
    }

    private function shippingMethodForAddress(array $data): ?ShippingMethod
    {
        $methods = ShippingMethod::query()->whereNull('seller_id')->where('is_store_option', false)->active();
        $district = mb_strtolower(trim((string) ($data['shipping_district'] ?? '')));
        if ($district !== '') {
            $code = $district === 'dhaka' ? 'inside_dhaka' : 'outside_dhaka';
            $districtMethod = (clone $methods)->where('code', $code)->first();
            if ($districtMethod) return $districtMethod;
        }

        return $methods->find($data['shipping_method_id']);
    }

    public function quoteCouponForCart(?Customer $customer, string $code, array $cartItems, float $shippingAmount = 0): array
    {
        $items = [];
        $subtotal = 0.0;
        foreach ($cartItems as $item) {
            $product = Product::query()->with(['seller', 'collections:id'])->availableForSale()->find($item['product_id']);
            if (!$product || ($product->seller_id && (!$product->seller || !$product->seller->is_active || $product->seller->status !== 'approved'))) {
                throw ValidationException::withMessages(['items' => ['One or more products are unavailable.']]);
            }
            $variationId = $item['variation_id'] ?? null;
            if ($product->product_type === 'variable' && !$variationId) throw ValidationException::withMessages(['items' => ["Select a variation for {$product->name}."]]);
            if ($product->product_type === 'simple' && $variationId) throw ValidationException::withMessages(['items' => ["{$product->name} does not have variations."]]);
            $variation = $variationId ? ProductVariation::query()->where('product_id', $product->id)->where('is_active', true)->find($variationId) : null;
            if ($variationId && !$variation) throw ValidationException::withMessages(['items' => ['A selected variation is unavailable.']]);
            $quantity = (int) $item['quantity'];
            $quote = $variation ? $this->inventory->previewVariation($variation, $quantity) : $this->inventory->previewProduct($product, $quantity);
            $subtotal = round($subtotal + $quote['subtotal'], 2);
            $items[] = compact('product', 'variation', 'quantity', 'quote');
        }
        $coupon = $this->resolveCoupon($code, $subtotal, $customer, $items);
        $discount = $this->discountForCart($coupon, $items, $subtotal, $shippingAmount);
        return [
            'coupon' => $coupon,
            'subtotal' => $subtotal,
            'shipping_amount' => $shippingAmount,
            'discount_amount' => $discount,
        ];
    }

    private function resolveCoupon(?string $couponCode, float $subtotal, ?Customer $customer, array $items, ?int $excludeOrderId = null): ?Coupon
    {
        return $this->resolveCoupons($couponCode, $subtotal, $customer, $items, $excludeOrderId)->first();
    }

    /** @return \Illuminate\Support\Collection<int, Coupon> */
    private function resolveCoupons(?string $couponCode, float $subtotal, ?Customer $customer, array $items, ?int $excludeOrderId = null): \Illuminate\Support\Collection
    {
        $coupons = collect();
        if ($couponCode) {
            $coupon = Coupon::where('code', preg_replace('/[^A-Z0-9_-]/', '', Str::upper($couponCode)))->first();
            if (!$coupon) throw ValidationException::withMessages(['coupon_code' => ['Coupon code was not found.']]);
            $this->assertCouponApplies($coupon, $subtotal, $customer, $items, $excludeOrderId, 'coupon_code');
            $coupons->push($coupon);
        }

        // Every eligible automatic discount is shown and included in the quote.
        Coupon::query()->where('is_automatic', true)->orderBy('id')->get()->each(function (Coupon $coupon) use ($subtotal, $customer, $items, $excludeOrderId, $coupons): void {
            if ($coupon->unusableReason() || ($coupon->minimum_order_amount !== null && $subtotal < (float) $coupon->minimum_order_amount)) return;
            if ($customer && $coupon->per_customer_limit !== null && CouponRedemption::where('coupon_id', $coupon->id)->where('customer_id', $customer->id)->when($excludeOrderId, fn ($query) => $query->where('order_id', '!=', $excludeOrderId))->count() >= $coupon->per_customer_limit) return;
            if ($this->couponMatches($coupon, $items) && !$coupons->contains('id', $coupon->id)) $coupons->push($coupon);
        });

        return $coupons;
    }

    private function assertCouponApplies(Coupon $coupon, float $subtotal, ?Customer $customer, array $items, ?int $excludeOrderId, string $field): void
    {
        if ($reason = $coupon->unusableReason()) throw ValidationException::withMessages([$field => [$reason]]);
        if ($coupon->minimum_order_amount !== null && $subtotal < (float) $coupon->minimum_order_amount) throw ValidationException::withMessages([$field => ['Minimum order amount not reached for this coupon.']]);
        if ($customer && $coupon->per_customer_limit !== null && CouponRedemption::where('coupon_id', $coupon->id)->where('customer_id', $customer->id)->when($excludeOrderId, fn ($query) => $query->where('order_id', '!=', $excludeOrderId))->count() >= $coupon->per_customer_limit) throw ValidationException::withMessages([$field => ['Coupon usage limit reached for this customer.']]);
        if (!$this->couponMatches($coupon, $items)) throw ValidationException::withMessages([$field => ['This discount does not apply to the items in your cart.']]);
    }

    private function couponMatches(Coupon $coupon, array $items): bool
    {
        $quantity = array_sum(array_map(fn ($item) => $item['quantity'], $items));
        if ($coupon->minimum_quantity !== null && $quantity < $coupon->minimum_quantity) return false;
        $products = array_map(fn ($item) => $item['product'], $items);
        if ($coupon->seller_id && !collect($products)->contains(fn ($product) => (int) $product->seller_id === (int) $coupon->seller_id)) return false;
        if ($coupon->discount_kind === 'bxgy') {
            return $this->bxgyDiscount($coupon, $items) > 0;
        }
        if (in_array($coupon->discount_kind, ['products', 'order'], true) || $coupon->applies_to === 'product') return count($this->selectedItems($items, $coupon->eligible_product_ids, $coupon->eligible_category_ids, $coupon->eligible_collection_ids)) > 0;
        return true;
    }

    private function discountForCart(Coupon $coupon, array $items, float $subtotal, float $shipping): float
    {
        if ($coupon->discount_kind === 'shipping' || $coupon->applies_to === 'shipping') return min($shipping, $coupon->discountForSubtotal($shipping));
        if ($coupon->discount_kind === 'bxgy') {
            return min($subtotal, $this->bxgyDiscount($coupon, $items));
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

    /** Match the console's Buy X Get Y rules without using one cart unit twice. */
    private function bxgyDiscount(Coupon $coupon, array $items): float
    {
        $buyIds = array_fill_keys(array_map(fn ($item) => (string) $item['product']->id, $this->selectedItems($items, $coupon->buy_product_ids, $coupon->buy_category_ids, $coupon->buy_collection_ids)), true);
        $getIds = array_fill_keys(array_map(fn ($item) => (string) $item['product']->id, $this->selectedItems($items, $coupon->eligible_product_ids, $coupon->eligible_category_ids, $coupon->eligible_collection_ids)), true);
        $units = [];
        foreach ($items as $item) {
            $quantity = max(0, (int) $item['quantity']);
            if ($quantity === 0) continue;
            $productId = (string) $item['product']->id;
            $price = (float) $item['quote']['subtotal'] / $quantity;
            for ($i = 0; $i < $quantity; $i++) {
                $units[] = ['price' => $price, 'buy' => isset($buyIds[$productId]), 'get' => isset($getIds[$productId]), 'used' => false];
            }
        }

        $buyQuantity = max(1, (int) ($coupon->buy_quantity ?? 1));
        $getQuantity = max(1, (int) ($coupon->get_quantity ?? 1));
        $limit = $coupon->uses_per_order === null ? PHP_INT_MAX : max(1, (int) $coupon->uses_per_order);
        $discount = 0.0;
        $uses = 0;

        while ($uses < $limit) {
            $buyIndexes = array_keys(array_filter($units, fn ($unit) => $unit['buy'] && !$unit['used']));
            usort($buyIndexes, fn ($a, $b) => $units[$b]['price'] <=> $units[$a]['price']);
            $buyIndexes = array_slice($buyIndexes, 0, $buyQuantity);
            if (count($buyIndexes) < $buyQuantity) break;
            foreach ($buyIndexes as $index) $units[$index]['used'] = true;

            $getIndexes = array_keys(array_filter($units, fn ($unit) => $unit['get'] && !$unit['used']));
            usort($getIndexes, fn ($a, $b) => $units[$a]['price'] <=> $units[$b]['price']);
            $getIndexes = array_slice($getIndexes, 0, $getQuantity);
            if ($getIndexes === []) break;
            foreach ($getIndexes as $index) {
                $units[$index]['used'] = true;
                $price = $units[$index]['price'];
                $discount += match ($coupon->reward_type) {
                    'free' => $price,
                    'fixed' => min($price, (float) $coupon->discount_value),
                    default => min($price, $price * ((float) $coupon->discount_value / 100)),
                };
            }
            $uses++;
        }

        return round($discount, 2);
    }
}
