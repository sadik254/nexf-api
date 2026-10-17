<?php

namespace App\Http\Controllers;

use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreLayoutController extends Controller
{
    private const TYPES = ['categories', 'collection', 'offerFour', 'offerWide', 'offerTwo', 'storeProducts'];
    private const OFFER_LAYOUTS = ['offerFour' => 'four', 'offerWide' => 'wide', 'offerTwo' => 'two'];
    public function publicShow(string $slug): JsonResponse { $seller = Seller::where('store_slug', $slug)->where('status', 'approved')->where('is_active', true)->firstOrFail(); return response()->json(['sections' => $seller->store_layout]); }
    public function sellerShow(Request $request): JsonResponse { return response()->json(['sections' => $this->seller($request)->store_layout]); }
    public function sellerSave(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $data = $request->validate(['sections' => ['present', 'array', 'max:30'], 'sections.*.id' => ['required', 'string', 'max:40', 'distinct'], 'sections.*.type' => ['required', Rule::in(self::TYPES)], 'sections.*.enabled' => ['required', 'boolean'], 'sections.*.title' => ['nullable', 'string', 'max:120'], 'sections.*.topSource' => ['nullable', Rule::in(['auto', 'picked'])], 'sections.*.gridSource' => ['nullable', Rule::in(['sales', 'newest', 'picked'])], 'sections.*.newSource' => ['nullable', Rule::in(['auto', 'picked'])], 'sections.*.showNewArrivals' => ['nullable', 'boolean'], 'sections.*.autoplay' => ['nullable', 'boolean'], 'sections.*.limit' => ['nullable', 'integer', 'min:1', 'max:50'], 'sections.*.source' => ['nullable', Rule::in(['category', 'tag', 'products'])], 'sections.*.sourceValue' => ['nullable', 'string', 'max:160'], 'sections.*.groupId' => ['nullable', 'string', 'max:40'], 'sections.*.topProductIds' => ['nullable', 'array', 'max:50'], 'sections.*.topProductIds.*' => ['string', 'max:40'], 'sections.*.newProductIds' => ['nullable', 'array', 'max:50'], 'sections.*.newProductIds.*' => ['string', 'max:40'], 'sections.*.productIds' => ['nullable', 'array', 'max:50'], 'sections.*.productIds.*' => ['string', 'max:40']]);
        $rows = $seller->promotions()->pluck('layout', 'id'); $keys = ['id', 'type', 'enabled', 'title', 'topSource', 'gridSource', 'newSource', 'showNewArrivals', 'autoplay', 'limit', 'source', 'sourceValue', 'groupId', 'topProductIds', 'newProductIds', 'productIds']; $sections = [];
        foreach ($data['sections'] as $i => $section) { if (isset(self::OFFER_LAYOUTS[$section['type']]) && !empty($section['groupId']) && ($rows[(int) $section['groupId']] ?? null) !== self::OFFER_LAYOUTS[$section['type']]) throw ValidationException::withMessages(["sections.{$i}.groupId" => ['Pick one of your own offer rows with this layout.']]); $sections[] = array_intersect_key($section, array_flip($keys)); }
        $seller->forceFill(['store_layout' => $sections])->save(); return response()->json(['sections' => $sections]);
    }
    private function seller(Request $request): Seller { $seller = $request->user(); abort_unless($seller instanceof Seller, 403); return $seller; }
}
