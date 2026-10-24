<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;

class CouponUsageLimitService
{
    public function reached(Coupon $coupon, ?Customer $customer, ?string $shippingPhone, ?int $excludeOrderId = null): bool
    {
        if ($coupon->per_customer_limit === null) return false;

        $query = CouponRedemption::query()->where('coupon_id', $coupon->id)
            ->when($excludeOrderId, fn ($rows) => $rows->where('order_id', '!=', $excludeOrderId));
        if ($customer) return $query->where('customer_id', $customer->id)->count() >= $coupon->per_customer_limit;

        $phone = $this->normalizePhone($shippingPhone);
        if ($phone === null) return false;
        // A phone can be stored as 01…, 8801…, or with separators. Narrow
        // in SQL first, then compare its canonical form in PHP.
        $count = $query->whereHas('order', fn ($orders) => $orders->where('shipping_phone', 'like', '%'.substr($phone, -10)))
            ->with('order:id,shipping_phone')->get()
            ->filter(fn (CouponRedemption $row) => $this->normalizePhone($row->order?->shipping_phone) === $phone)
            ->count();
        return $count >= $coupon->per_customer_limit;
    }

    private function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (str_starts_with($digits, '880')) $digits = substr($digits, 2);
        elseif (str_starts_with($digits, '1')) $digits = "0{$digits}";
        return preg_match('/^01[3-9]\d{8}$/', $digits) ? $digits : null;
    }
}
