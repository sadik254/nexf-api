<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\HomepageOfferSet;
use App\Models\ProductCategory;
use App\Models\ProductCollection;
use App\Models\SiteSetting;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HomepageLayoutController extends Controller
{
    public function show(): JsonResponse
    {
        $sections = SiteSetting::find('home_layout')?->payload ?? config('site-content.home_layout');
        $defaults = HomepageOfferSet::query()->get()->groupBy('row_type')->map(fn ($sets) => $sets->sortBy('id')->first()->id);
        $sections = collect($sections)->map(function ($section) use ($defaults) {
            $row = ['offerFour' => 'four', 'offerWide' => 'wide', 'offerTwo' => 'two', 'coupons' => 'coupons'][$section['type']] ?? null;
            if ($row && empty($section['offer_set_id']) && isset($defaults[$row])) {
                $section['offer_set_id'] = $defaults[$row];
            }

            return $section;
        })->values();

        return response()->json($sections);
    }

    public function update(Request $request): JsonResponse
    {
        $admin = $request->user();
        abort_unless($admin instanceof Admin, 403);
        $data = $request->validate([
            'sections' => ['required', 'array', 'max:30'],
            'sections.*.id' => ['required', 'string', 'max:60', 'distinct'],
            'sections.*.type' => ['required', Rule::in(['hero', 'trust', 'categories', 'trending', 'offerFour', 'offerWide', 'offerTwo', 'coupons', 'collection', 'reviews', 'featured', 'support', 'clients'])],
            'sections.*.enabled' => ['required', 'boolean'],
            'sections.*.title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'sections.*.subtitle' => ['sometimes', 'nullable', 'string', 'max:300'],
            'sections.*.clientsLayout' => ['sometimes', Rule::in(['grid', 'marquee'])],
            'sections.*.collection_id' => ['sometimes', 'nullable', 'integer', 'exists:product_collections,id'],
            'sections.*.topSource' => ['sometimes', 'in:auto,picked'],
            'sections.*.newSource' => ['sometimes', 'in:auto,picked'],
            'sections.*.showNewArrivals' => ['sometimes', 'boolean'],
            'sections.*.source' => ['sometimes', 'in:collection,category,tag,products'],
            'sections.*.sourceValue' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sections.*.limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sections.*.autoplay' => ['sometimes', 'boolean'],
            'sections.*.support' => ['sometimes', 'array:badge,title,body,messengerLabel,messenger,whatsappLabel,whatsapp'],
            'sections.*.support.badge' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sections.*.support.title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'sections.*.support.body' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'sections.*.support.messengerLabel' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sections.*.support.whatsappLabel' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sections.*.support.messenger' => ['sometimes', 'nullable', 'url', 'max:500', 'regex:/^https?:\/\//'],
            'sections.*.support.whatsapp' => ['sometimes', 'nullable', 'url', 'max:500', 'regex:/^https?:\/\//'],
            'sections.*.topProductIds' => ['sometimes', 'array', 'max:100'],
            'sections.*.topProductIds.*' => ['integer', 'exists:products,id'],
            'sections.*.newProductIds' => ['sometimes', 'array', 'max:100'],
            'sections.*.newProductIds.*' => ['integer', 'exists:products,id'],
            'sections.*.productIds' => ['sometimes', 'array', 'max:100'],
            'sections.*.productIds.*' => ['integer', 'exists:products,id'],
            'sections.*.reviewIds' => ['sometimes', 'array', 'max:100'],
            'sections.*.reviewIds.*' => ['integer', 'exists:reviews,id'],
            'sections.*.offer_set_id' => ['sometimes', 'nullable', 'integer', 'exists:homepage_offer_sets,id'],
        ]);
        foreach ($data['sections'] as $index => $section) {
            foreach (['topProductIds', 'newProductIds', 'productIds', 'reviewIds'] as $key) {
                $ids = $section[$key] ?? [];
                if (count($ids) !== count(array_unique($ids))) {
                    throw ValidationException::withMessages(["sections.{$index}.{$key}" => 'Each selection must be unique within its block.']);
                }
            }
            if ($section['type'] === 'collection') {
                $source = $section['source'] ?? 'collection';
                $value = $section['sourceValue'] ?? $section['collection_id'] ?? null;
                $exists = match ($source) {
                    'products' => true,
                    'category' => ProductCategory::where('slug', $value)->exists(),
                    'tag' => Tag::where('slug', $value)->exists(),
                    default => ProductCollection::whereKey($value)->exists(),
                };
                if (! $exists) {
                    throw ValidationException::withMessages(["sections.{$index}.sourceValue" => 'Choose an existing source.']);
                }
            }

            if ($section['type'] === 'clients' && isset($section['limit']) && ! in_array((int) $section['limit'], [6, 12, 18, 24, 36], true)) {
                throw ValidationException::withMessages(["sections.{$index}.limit" => 'Choose 6, 12, 18, 24, or 36 logos.']);
            }

            if ($section['type'] === 'collection' && ($section['source'] ?? 'collection') === 'collection' && empty($section['collection_id']) && empty($section['sourceValue'])) {
                throw ValidationException::withMessages(["sections.{$index}.collection_id" => 'Choose a collection for this section.']);
            }
            if (array_key_exists($section['type'], ['offerFour' => 'four', 'offerWide' => 'wide', 'offerTwo' => 'two', 'coupons' => 'coupons']) && ! empty($section['offer_set_id'])) {
                $set = HomepageOfferSet::find($section['offer_set_id']);
                $expected = ['offerFour' => 'four', 'offerWide' => 'wide', 'offerTwo' => 'two', 'coupons' => 'coupons'][$section['type']];
                if (! $set || $set->row_type !== $expected) {
                    throw ValidationException::withMessages(["sections.{$index}.offer_set_id" => 'Choose a matching offer set.']);
                }
            }
        }
        SiteSetting::updateOrCreate(['key' => 'home_layout'], ['payload' => $data['sections']]);

        return $this->show();
    }
}
