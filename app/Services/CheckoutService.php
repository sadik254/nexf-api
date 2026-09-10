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
    public function __construct(private InventoryService $inventory) {}

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
            $product = Product::query()->with('seller')->where('status', 'active')->find($itemData['product_id']);
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

        $coupon = $this->resolveCoupon($data['coupon_code'] ?? null, $subtotal, $customer);
        $discount = $coupon ? $coupon->discountForSubtotal($subtotal) : 0.0;
        return compact('paymentMethod', 'shippingMethod', 'items', 'coupon', 'subtotal') + [
            'discount_total' => $discount,
            'total' => round($subtotal + (float) $shippingMethod->charge - $discount, 2),
        ];
    }

    private function resolveCoupon(?string $couponCode, float $subtotal, Customer $customer): ?Coupon
    {
        if (!$couponCode) return null;
        $coupon = Coupon::where('code', preg_replace('/[^A-Z0-9_-]/', '', Str::upper($couponCode)))->first();
        if (!$coupon || !$coupon->isUsable()) throw ValidationException::withMessages(['coupon_code' => ['Coupon is invalid or expired.']]);
        if ($coupon->minimum_order_amount !== null && $subtotal < (float) $coupon->minimum_order_amount) throw ValidationException::withMessages(['coupon_code' => ['Minimum order amount not reached for this coupon.']]);
        if ($coupon->per_customer_limit !== null && CouponRedemption::where('coupon_id', $coupon->id)->where('customer_id', $customer->id)->count() >= $coupon->per_customer_limit) throw ValidationException::withMessages(['coupon_code' => ['Coupon usage limit reached for this customer.']]);
        return $coupon;
    }
}
