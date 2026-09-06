<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (!$this->isSuperAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(1, min($perPage, 100));
        $search = (string) $request->query('search', '');

        $query = Coupon::query()->latest();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        return response()->json($query->paginate($perPage));
    }

    public function show(Request $request, Coupon $coupon): JsonResponse
    {
        if (!$this->isSuperAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($coupon->load('creator'));
    }

    public function store(Request $request): JsonResponse
    {
        /** @var Admin|null $actor */
        $actor = $request->user();
        if (!$this->isSuperAdmin($actor)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $this->validateCoupon($request);
        $code = $this->normalizeCode($data['code']);

        if (!$this->dateWindowIsValid($data['starts_at'] ?? null, $data['expires_at'] ?? null)) {
            return response()->json(['message' => 'Coupon expiry must be after start date.'], 422);
        }

        if ($code === '') {
            return response()->json(['message' => 'Coupon code is invalid.'], 422);
        }

        if (Coupon::where('code', $code)->exists()) {
            return response()->json(['message' => 'Coupon code already taken.'], 422);
        }

        $coupon = Coupon::create([
            'created_by_admin_id' => $actor->id,
            'code' => $code,
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_value'],
            'minimum_order_amount' => $data['minimum_order_amount'] ?? null,
            'maximum_discount_amount' => $data['maximum_discount_amount'] ?? null,
            'usage_limit' => $data['usage_limit'] ?? null,
            'used_count' => 0,
            'per_customer_limit' => $data['per_customer_limit'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ]);

        return response()->json([
            'message' => 'Coupon created successfully.',
            'coupon' => $coupon,
        ], 201);
    }

    public function update(Request $request, Coupon $coupon): JsonResponse
    {
        if (!$this->isSuperAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $this->validateCoupon($request, false);

        $startsAt = array_key_exists('starts_at', $data) ? $data['starts_at'] : $coupon->starts_at?->toDateTimeString();
        $expiresAt = array_key_exists('expires_at', $data) ? $data['expires_at'] : $coupon->expires_at?->toDateTimeString();

        if (!$this->dateWindowIsValid($startsAt, $expiresAt)) {
            return response()->json(['message' => 'Coupon expiry must be after start date.'], 422);
        }

        $code = $coupon->code;
        if (array_key_exists('code', $data)) {
            $code = $this->normalizeCode($data['code']);
            if ($code === '') {
                return response()->json(['message' => 'Coupon code is invalid.'], 422);
            }

            $exists = Coupon::where('code', $code)
                ->where('id', '!=', $coupon->id)
                ->exists();

            if ($exists) {
                return response()->json(['message' => 'Coupon code already taken.'], 422);
            }
        }

        $coupon->fill([
            'code' => $code,
            'name' => array_key_exists('name', $data) ? $data['name'] : $coupon->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $coupon->description,
            'discount_type' => $data['discount_type'] ?? $coupon->discount_type,
            'discount_value' => $data['discount_value'] ?? $coupon->discount_value,
            'minimum_order_amount' => array_key_exists('minimum_order_amount', $data) ? $data['minimum_order_amount'] : $coupon->minimum_order_amount,
            'maximum_discount_amount' => array_key_exists('maximum_discount_amount', $data) ? $data['maximum_discount_amount'] : $coupon->maximum_discount_amount,
            'usage_limit' => array_key_exists('usage_limit', $data) ? $data['usage_limit'] : $coupon->usage_limit,
            'per_customer_limit' => array_key_exists('per_customer_limit', $data) ? $data['per_customer_limit'] : $coupon->per_customer_limit,
            'starts_at' => array_key_exists('starts_at', $data) ? $data['starts_at'] : $coupon->starts_at,
            'expires_at' => array_key_exists('expires_at', $data) ? $data['expires_at'] : $coupon->expires_at,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $coupon->is_active,
        ])->save();

        return response()->json([
            'message' => 'Coupon updated successfully.',
            'coupon' => $coupon,
        ]);
    }

    public function destroy(Request $request, Coupon $coupon): JsonResponse
    {
        if (!$this->isSuperAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $coupon->delete();

        return response()->json(['message' => 'Coupon deleted successfully.']);
    }

    public function validateCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'subtotal' => ['required', 'numeric', 'min:0'],
        ]);

        $code = $this->normalizeCode($data['code']);
        $coupon = Coupon::where('code', $code)->first();

        if (!$coupon || !$coupon->isUsable()) {
            return response()->json(['message' => 'Coupon is invalid or expired.'], 422);
        }

        $subtotal = (float) $data['subtotal'];
        if ($coupon->minimum_order_amount !== null && $subtotal < (float) $coupon->minimum_order_amount) {
            return response()->json([
                'message' => 'Minimum order amount not reached.',
                'minimum_order_amount' => (string) $coupon->minimum_order_amount,
            ], 422);
        }

        $customer = $request->user();
        if ($customer instanceof Customer && $coupon->per_customer_limit !== null) {
            $usedByCustomer = CouponRedemption::where('coupon_id', $coupon->id)
                ->where('customer_id', $customer->id)
                ->count();

            if ($usedByCustomer >= $coupon->per_customer_limit) {
                return response()->json(['message' => 'Coupon usage limit reached for this customer.'], 422);
            }
        }

        return response()->json([
            'message' => 'Coupon is valid.',
            'coupon' => $coupon,
            'discount_amount' => $coupon->discountForSubtotal($subtotal),
        ]);
    }

    private function validateCoupon(Request $request, bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'code' => [$required, 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'discount_type' => [$required, Rule::in(['fixed', 'percentage'])],
            'discount_value' => [$required, 'numeric', 'min:0.01'],
            'minimum_order_amount' => ['nullable', 'numeric', 'min:0'],
            'maximum_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function normalizeCode(string $code): string
    {
        return (string) preg_replace('/[^A-Z0-9_-]/', '', Str::upper($code));
    }

    private function dateWindowIsValid(?string $startsAt, ?string $expiresAt): bool
    {
        if ($startsAt === null || $expiresAt === null) {
            return true;
        }

        return strtotime($expiresAt) > strtotime($startsAt);
    }

    private function isSuperAdmin($actor): bool
    {
        return $actor instanceof Admin && $actor->role === 'super_admin';
    }
}
