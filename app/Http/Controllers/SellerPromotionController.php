<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Coupon;
use App\Models\Seller;
use App\Models\SellerPromotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class SellerPromotionController extends Controller
{
    public function publicIndex(): JsonResponse
    {
        $rows = SellerPromotion::query()
            ->whereHas('seller', fn ($q) => $q->where('is_active', true)->where('status', 'approved'))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', today()))
            ->with('seller:id,store_name,store_slug')
            ->orderByDesc('pinned')->latest()->get();

        return response()->json($rows);
    }

    public function sellerIndex(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        return response()->json($seller->promotions()->with('seller:id,store_name,store_slug')->latest()->get());
    }

    public function sellerStore(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $data = $this->validated($request, $seller->id, true, false);
        $data['seller_id'] = $seller->id;
        $data['pinned'] = $data['pinned'] ?? false;
        return response()->json(SellerPromotion::create($data)->load('seller:id,store_name,store_slug'), 201);
    }

    public function sellerUpdate(Request $request, SellerPromotion $promotion): JsonResponse
    {
        $this->owned($request, $promotion);
        $promotion->update($this->validated($request, $promotion->seller_id, false, false));
        return response()->json($promotion->fresh()->load('seller:id,store_name,store_slug'));
    }

    public function sellerDestroy(Request $request, SellerPromotion $promotion): JsonResponse
    {
        $this->owned($request, $promotion);
        $promotion->delete();
        return response()->json(['message' => 'Promotion deleted.']);
    }

    public function adminIndex(): JsonResponse
    {
        return response()->json(SellerPromotion::with('seller:id,store_name,store_slug')->orderByDesc('pinned')->latest()->get());
    }

    public function adminStore(Request $request): JsonResponse
    {
        $this->admin($request);
        $data = $this->validated($request, (int) $request->input('seller_id'), true, true);
        return response()->json(SellerPromotion::create($data)->load('seller:id,store_name,store_slug'), 201);
    }

    public function adminUpdate(Request $request, SellerPromotion $promotion): JsonResponse
    {
        $this->admin($request);
        $sellerId = (int) $request->input('seller_id', $promotion->seller_id);
        $data = $this->validated($request, $sellerId, false, true);
        if ($sellerId !== $promotion->seller_id && !array_key_exists('code', $data) && $promotion->code && !$this->couponUsable($sellerId, $promotion->code)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['code' => ['Choose an active coupon code belonging to the selected seller.']]);
        }
        $promotion->update($data);
        return response()->json($promotion->fresh()->load('seller:id,store_name,store_slug'));
    }

    public function adminDestroy(Request $request, SellerPromotion $promotion): JsonResponse
    {
        $this->admin($request);
        $promotion->delete();
        return response()->json(['message' => 'Promotion deleted.']);
    }

    private function validated(Request $request, int $sellerId, bool $creating, bool $admin): array
    {
        $rules = [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'body' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'code' => ['sometimes', 'nullable', 'string', 'max:80'],
            'discount_label' => [$creating ? 'required' : 'sometimes', 'string', 'max:60'],
            'expires_at' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:1000', 'regex:/^(\/|https:\/\/)/'],
            'theme' => ['sometimes', Rule::in(['slate', 'blue', 'rose', 'amber', 'emerald', 'violet'])],
            'pinned' => ['sometimes', 'boolean'],
        ];
        if ($admin) {
            $rules['seller_id'] = [$creating ? 'required' : 'sometimes', 'integer', 'exists:sellers,id'];
        }
        $data = $request->validate($rules);
        if (!empty($data['code'])) {
            $data['code'] = Str::upper(trim($data['code']));
            if (!$this->couponUsable($sellerId, $data['code'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'code' => ['Choose an active coupon code belonging to this seller.'],
                ]);
            }
        }
        return $data;
    }

    private function couponUsable(int $sellerId, string $code): bool
    {
        return Coupon::query()->where('seller_id', $sellerId)->where('code', Str::upper(trim($code)))
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->where(fn ($q) => $q->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit'))
            ->exists();
    }

    private function seller(Request $request): Seller
    {
        $seller = $request->user();
        abort_unless($seller instanceof Seller, 403);
        return $seller;
    }

    private function owned(Request $request, SellerPromotion $promotion): void
    {
        abort_unless($this->seller($request)->id === $promotion->seller_id, 404);
    }

    private function admin(Request $request): void
    {
        abort_unless($request->user() instanceof Admin, 403);
    }
}
