<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Seller;
use App\Models\SellerPromotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SellerPromotionController extends Controller
{
    private const WITH = 'seller:id,store_name,store_slug';

    public function publicIndex(): JsonResponse
    {
        return response()->json(SellerPromotion::query()->where('status', 'approved')->whereHas('seller', fn ($q) => $q->where('is_active', true)->where('status', 'approved'))->with(self::WITH)->orderByDesc('pinned')->latest()->get());
    }
    public function sellerIndex(Request $request): JsonResponse { return response()->json($this->seller($request)->promotions()->with(self::WITH)->latest()->get()); }
    public function sellerStore(Request $request): JsonResponse
    {
        $seller = $this->seller($request); $data = $this->validated($request, null, false);
        $data += ['seller_id' => $seller->id, 'pinned' => false, 'status' => 'draft'];
        return response()->json(SellerPromotion::create($data)->load(self::WITH), 201);
    }
    public function sellerUpdate(Request $request, SellerPromotion $promotion): JsonResponse
    {
        $this->owned($request, $promotion); $promotion->fill($this->validated($request, $promotion, false));
        if ($promotion->isDirty()) $promotion->fill(['status' => 'draft', 'review_note' => null, 'reviewed_at' => null]);
        $promotion->save(); return response()->json($promotion->fresh()->load(self::WITH));
    }
    public function sellerSubmit(Request $request, SellerPromotion $promotion): JsonResponse
    {
        $this->owned($request, $promotion);
        if (!in_array($promotion->status, ['draft', 'rejected'], true)) throw ValidationException::withMessages(['status' => ['This offer is already with the NEXF team or live.']]);
        $this->ensureComplete($promotion->blocks ?? []);
        $promotion->update(['status' => 'pending', 'review_note' => null, 'reviewed_at' => null]);
        return response()->json($promotion->fresh()->load(self::WITH));
    }
    public function sellerDestroy(Request $request, SellerPromotion $promotion): JsonResponse { $this->owned($request, $promotion); $promotion->delete(); return response()->json(['message' => 'Offer deleted.']); }
    public function adminIndex(Request $request): JsonResponse { $this->admin($request); return response()->json(SellerPromotion::with(self::WITH)->orderByDesc('pinned')->latest()->get()); }
    public function adminStore(Request $request): JsonResponse
    {
        $this->admin($request); $data = $this->validated($request, null, true); $data['status'] = $data['status'] ?? 'approved';
        if ($data['status'] === 'approved') $this->ensureComplete($data['blocks']); $data['reviewed_at'] = now();
        return response()->json(SellerPromotion::create($data)->load(self::WITH), 201);
    }
    public function adminUpdate(Request $request, SellerPromotion $promotion): JsonResponse
    {
        $this->admin($request); $data = $this->validated($request, $promotion, true); $status = $data['status'] ?? $promotion->status;
        if ($status !== $promotion->status) $data['reviewed_at'] = now();
        if ($status === 'approved') $this->ensureComplete($data['blocks'] ?? $promotion->blocks ?? []);
        if ($status !== 'rejected') $data['review_note'] = null;
        $promotion->update($data); return response()->json($promotion->fresh()->load(self::WITH));
    }
    public function adminDestroy(Request $request, SellerPromotion $promotion): JsonResponse { $this->admin($request); $promotion->delete(); return response()->json(['message' => 'Offer deleted.']); }

    private function validated(Request $request, ?SellerPromotion $promotion, bool $admin): array
    {
        $creating = $promotion === null;
        $rules = ['title' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'], 'layout' => [$creating ? 'required' : 'sometimes', Rule::in(array_keys(SellerPromotion::LAYOUTS))], 'blocks' => ['sometimes', 'array'], 'blocks.*.title' => ['nullable', 'string', 'max:160'], 'blocks.*.body' => ['nullable', 'string', 'max:2000'], 'blocks.*.cta' => ['nullable', 'string', 'max:80'], 'blocks.*.href' => ['nullable', 'string', 'max:500', 'regex:/^\/(?![\/\\\\])/'], 'blocks.*.image' => ['nullable', 'string', 'max:1000', 'regex:/^(\/|https:\/\/)/'], 'blocks.*.mobile_image' => ['nullable', 'string', 'max:1000', 'regex:/^(\/|https:\/\/)/'], 'blocks.*.theme' => ['nullable', Rule::in(SellerPromotion::THEMES)], 'blocks.*.coupon_id' => ['nullable', 'integer', 'exists:coupons,id'], 'blocks.*.expires_at' => ['nullable', 'date'], 'blocks.*.hidden' => ['sometimes', 'boolean']];
        if ($admin) { $rules['seller_id'] = [$creating ? 'required' : 'sometimes', 'integer', 'exists:sellers,id']; $rules['pinned'] = ['sometimes', 'boolean']; $rules['status'] = ['sometimes', Rule::in(SellerPromotion::STATUSES)]; $rules['review_note'] = ['nullable', 'string', 'max:500', 'required_if:status,rejected']; }
        $data = $request->validate($rules); $layout = $data['layout'] ?? $promotion?->layout; $count = SellerPromotion::LAYOUTS[$layout];
        if (array_key_exists('blocks', $data)) { if ($count !== null && count($data['blocks']) !== $count) throw ValidationException::withMessages(['blocks' => ["This layout takes {$count} ".($count === 1 ? 'block' : 'blocks').'.']]); $data['blocks'] = array_map(fn ($block) => $this->block($block), array_values($data['blocks'])); }
        elseif ($count !== null && ($creating || count($promotion->blocks ?? []) !== $count)) { $kept = array_slice($promotion?->blocks ?? [], 0, $count); $data['blocks'] = array_map(fn ($i) => $this->block($kept[$i] ?? []), range(0, $count - 1)); }
        return $data;
    }
    private function block(array $block): array { $text = fn ($key) => isset($block[$key]) && trim((string) $block[$key]) !== '' ? trim((string) $block[$key]) : null; return ['title' => $text('title'), 'body' => $text('body'), 'cta' => $text('cta'), 'href' => $text('href'), 'image' => $text('image'), 'mobile_image' => $text('mobile_image'), 'theme' => $block['theme'] ?? 'blue', 'coupon_id' => $block['coupon_id'] ?? null, 'expires_at' => $block['expires_at'] ?? null, 'hidden' => (bool) ($block['hidden'] ?? false)]; }
    private function ensureComplete(array $blocks): void { foreach ($blocks as $i => $block) if (empty($block['title']) || empty($block['image'])) throw ValidationException::withMessages(['blocks' => ['Block '.($i + 1).' needs a title and an image.']]); }
    private function seller(Request $request): Seller { $seller = $request->user(); abort_unless($seller instanceof Seller, 403); return $seller; }
    private function owned(Request $request, SellerPromotion $promotion): void { abort_unless($this->seller($request)->id === $promotion->seller_id, 404); }
    private function admin(Request $request): void { abort_unless($request->user() instanceof Admin, 403); }
}
