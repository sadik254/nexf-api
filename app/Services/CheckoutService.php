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

    public function preview(Customer $customer, array $data): array
    {
        $paymentMethod = PaymentMethod::query()->active()->find($data['payment_method_id']);
        $shippingMethod = ShippingMethod::query()->active()->find($data['shipping_method_id']);
        if (!$paymentMethod || !$shippingMethod) {
            throw ValidationException::withMessages(['checkout' => ['Selected payment or shipping method is unavailable.']]);
        }

        $items = [];
        $subtotal = 0.0;
        foreach ($data['items'] as $itemData) {
            $product = Product::query()->with('seller')->whereIn('status', ['active', 'unlisted'])->find($itemData['product_id']);
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
        ], $items), $shippingMethod);
        $coupon = $this->resolveCoupon($data['coupon_code'] ?? null, $subtotal, $customer, $items);
        $discount = $coupon ? ($coupon->applies_to === 'shipping' ? min((float) $shipping['total'], $coupon->discountForSubtotal((float) $shipping['total'])) : $coupon->discountForSubtotal($subtotal)) : 0.0;
        $total = round($subtotal + $shipping['total'] - $discount, 2);
        $this->fraudGuard->assertAllowed($customer, $data + ['payment_method_code' => $paymentMethod->code], $total);

        return compact('paymentMethod', 'shippingMethod', 'items', 'coupon', 'subtotal') + [
            'discount_total' => $discount,
            'shipping_groups' => $shipping['groups'],
            'shipping_charge' => $shipping['total'],
            'total' => $total,
        ];
    }

    private function resolveCoupon(?string $couponCode, float $subtotal, Customer $customer, array $items): ?Coupon
    {
        $coupon = $couponCode ? Coupon::where('code', preg_replace('/[^A-Z0-9_-]/', '', Str::upper($couponCode)))->first() : Coupon::query()->where('is_automatic', true)->get()->first(fn (Coupon $candidate) => !$candidate->unusableReason() && $this->couponMatches($candidate, $items));
        if (!$coupon && !$couponCode) return null;
        if (!$coupon) throw ValidationException::withMessages(['coupon_code' => ['Coupon code was not found.']]);
        if ($reason = $coupon->unusableReason()) throw ValidationException::withMessages(['coupon_code' => [$reason]]);
        if ($coupon->minimum_order_amount !== null && $subtotal < (float) $coupon->minimum_order_amount) throw ValidationException::withMessages(['coupon_code' => ['Minimum order amount not reached for this coupon.']]);
        if ($coupon->per_customer_limit !== null && CouponRedemption::where('coupon_id', $coupon->id)->where('customer_id', $customer->id)->count() >= $coupon->per_customer_limit) throw ValidationException::withMessages(['coupon_code' => ['Coupon usage limit reached for this customer.']]);
        if (!$this->couponMatches($coupon, $items)) throw ValidationException::withMessages(['coupon_code' => ['This discount does not apply to the items in your cart.']]);
        return $coupon;
    }

    private function couponMatches(Coupon $coupon, array $items): bool
    {
        $quantity = array_sum(array_map(fn ($item) => $item['quantity'], $items));
        if ($coupon->minimum_quantity !== null && $quantity < $coupon->minimum_quantity) return false;
        if ($coupon->applies_to !== 'product') return true;
        $products = array_map(fn ($item) => $item['product'], $items);
        if ($coupon->seller_id && !collect($products)->contains(fn ($product) => (int) $product->seller_id === (int) $coupon->seller_id)) return false;
        if ($coupon->eligible_product_ids && !collect($products)->contains(fn ($product) => in_array($product->id, $coupon->eligible_product_ids, true))) return false;
        if ($coupon->eligible_category_ids && !collect($products)->contains(fn ($product) => in_array($product->category_id, $coupon->eligible_category_ids, true))) return false;
        return true;
    }
}
