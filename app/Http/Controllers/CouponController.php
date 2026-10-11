<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Coupon;
use App\Models\Customer;
use App\Services\CheckoutService;
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

        $filters = $request->validate(['status' => ['nullable', 'in:active,scheduled,expired,inactive']]);
        $query = Coupon::query()->latest();
        $now = now();
        switch ($filters['status'] ?? null) {
            case 'inactive': $query->where('is_active', false); break;
            case 'expired': $query->where('is_active', true)->where('expires_at', '<', $now); break;
            case 'scheduled':
                $query->where('is_active', true)->where('starts_at', '>', $now)
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now)); break;
            case 'active':
                $query->where('is_active', true)
                    ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now)); break;
        }

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
        // The editor intentionally sends AUTO as its placeholder for an
        // automatic rule; turn it into a unique internal redemption code.
        $code = !empty($data['is_automatic']) && (empty($data['code']) || Str::upper((string) $data['code']) === 'AUTO') ? 'AUTO-'.Str::upper(Str::random(10)) : $this->normalizeCode($data['code'] ?? '');

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
            'seller_id' => $data['seller_id'] ?? null,
            'code' => $code,
            'is_automatic' => (bool) ($data['is_automatic'] ?? false),
            'discount_kind' => $data['discount_kind'] ?? 'order', 'combines' => (bool) ($data['combines'] ?? false),
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'discount_type' => $data['discount_type'],
            'applies_to' => $data['applies_to'] ?? 'order',
            'discount_value' => $data['discount_value'],
            'minimum_order_amount' => $data['minimum_order_amount'] ?? null,
            'minimum_quantity' => $data['minimum_quantity'] ?? null,
            'eligible_product_ids' => $data['eligible_product_ids'] ?? null,
            'eligible_category_ids' => $data['eligible_category_ids'] ?? null,
            'eligible_collection_ids' => $data['eligible_collection_ids'] ?? null, 'buy_product_ids' => $data['buy_product_ids'] ?? null, 'buy_category_ids' => $data['buy_category_ids'] ?? null, 'buy_collection_ids' => $data['buy_collection_ids'] ?? null, 'buy_quantity' => $data['buy_quantity'] ?? null, 'get_quantity' => $data['get_quantity'] ?? null, 'reward_type' => $data['reward_type'] ?? null,
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
            'seller_id' => array_key_exists('seller_id', $data) ? $data['seller_id'] : $coupon->seller_id,
            'is_automatic' => array_key_exists('is_automatic', $data) ? (bool) $data['is_automatic'] : $coupon->is_automatic,
            'discount_kind' => $data['discount_kind'] ?? $coupon->discount_kind, 'combines' => array_key_exists('combines', $data) ? (bool) $data['combines'] : $coupon->combines,
            'name' => array_key_exists('name', $data) ? $data['name'] : $coupon->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $coupon->description,
            'discount_type' => $data['discount_type'] ?? $coupon->discount_type,
            'applies_to' => $data['applies_to'] ?? $coupon->applies_to,
            'discount_value' => $data['discount_value'] ?? $coupon->discount_value,
            'minimum_order_amount' => array_key_exists('minimum_order_amount', $data) ? $data['minimum_order_amount'] : $coupon->minimum_order_amount,
            'minimum_quantity' => array_key_exists('minimum_quantity', $data) ? $data['minimum_quantity'] : $coupon->minimum_quantity,
            'eligible_product_ids' => array_key_exists('eligible_product_ids', $data) ? $data['eligible_product_ids'] : $coupon->eligible_product_ids,
            'eligible_category_ids' => array_key_exists('eligible_category_ids', $data) ? $data['eligible_category_ids'] : $coupon->eligible_category_ids,
            'eligible_collection_ids' => array_key_exists('eligible_collection_ids', $data) ? $data['eligible_collection_ids'] : $coupon->eligible_collection_ids, 'buy_product_ids' => array_key_exists('buy_product_ids', $data) ? $data['buy_product_ids'] : $coupon->buy_product_ids, 'buy_category_ids' => array_key_exists('buy_category_ids', $data) ? $data['buy_category_ids'] : $coupon->buy_category_ids, 'buy_collection_ids' => array_key_exists('buy_collection_ids', $data) ? $data['buy_collection_ids'] : $coupon->buy_collection_ids, 'buy_quantity' => array_key_exists('buy_quantity', $data) ? $data['buy_quantity'] : $coupon->buy_quantity, 'get_quantity' => array_key_exists('get_quantity', $data) ? $data['get_quantity'] : $coupon->get_quantity, 'reward_type' => array_key_exists('reward_type', $data) ? $data['reward_type'] : $coupon->reward_type,
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

    public function validateCode(Request $request, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'subtotal' => ['required_without:items', 'numeric', 'min:0'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.product_id' => ['required_with:items', 'integer', 'exists:products,id'],
            'items.*.variation_id' => ['nullable', 'integer', 'exists:product_variations,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'shipping_amount' => ['sometimes', 'numeric', 'min:0'],
        ]);

        $code = $this->normalizeCode($data['code']);
        $coupon = Coupon::where('code', $code)->first();

        if (!$coupon) {
            return response()->json(['message' => 'Coupon code was not found.'], 422);
        }

        if ($reason = $coupon->unusableReason()) {
            return response()->json(['message' => $reason], 422);
        }

        if (!empty($data['items'])) {
            $customer = $request->user() instanceof Customer ? $request->user() : null;
            $quote = $checkout->quoteCouponForCart($customer, $code, $data['items'], (float) ($data['shipping_amount'] ?? 0));
            return response()->json([
                'message' => 'Coupon is valid.',
                'coupon' => $quote['coupon'],
                'subtotal' => $quote['subtotal'],
                'discount_amount' => $quote['discount_amount'],
            ]);
        }

        $subtotal = (float) $data['subtotal'];
        if ($coupon->minimum_order_amount !== null && $subtotal < (float) $coupon->minimum_order_amount) {
            return response()->json([
                'message' => 'Minimum order amount not reached.',
                'minimum_order_amount' => (string) $coupon->minimum_order_amount,
            ], 422);
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
            'code' => [$creating ? 'required_without:is_automatic' : 'sometimes', 'nullable', 'string', 'max:64'],
            'seller_id' => ['nullable', 'integer', 'exists:sellers,id'],
            'is_automatic' => ['sometimes', 'boolean'],
            'discount_kind' => ['sometimes', Rule::in(['products','bxgy','order','shipping'])], 'combines' => ['sometimes','boolean'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'discount_type' => [$required, Rule::in(['fixed', 'percentage'])],
            'applies_to' => ['sometimes', Rule::in(['order','product','shipping'])],
            'discount_value' => [$required, 'numeric', 'min:0'],
            'minimum_order_amount' => ['nullable', 'numeric', 'min:0'],
            'minimum_quantity' => ['nullable', 'integer', 'min:1'],
            'eligible_product_ids' => ['nullable', 'array'], 'eligible_product_ids.*' => ['integer', 'exists:products,id'],
            'eligible_category_ids' => ['nullable', 'array'], 'eligible_category_ids.*' => ['integer', 'exists:product_categories,id'],
            'eligible_collection_ids' => ['nullable','array'], 'eligible_collection_ids.*' => ['integer','exists:product_collections,id'],
            'buy_product_ids' => ['nullable','array'], 'buy_product_ids.*' => ['integer','exists:products,id'], 'buy_category_ids' => ['nullable','array'], 'buy_category_ids.*' => ['integer','exists:product_categories,id'], 'buy_collection_ids' => ['nullable','array'], 'buy_collection_ids.*' => ['integer','exists:product_collections,id'], 'buy_quantity'=>['nullable','integer','min:1'], 'get_quantity'=>['nullable','integer','min:1'], 'reward_type'=>['nullable',Rule::in(['free','percentage','fixed'])],
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
