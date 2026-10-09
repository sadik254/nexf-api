<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HomepageLayoutController extends Controller
{
    public function show(): JsonResponse
    {
        $sections = SiteSetting::find('home_layout')?->payload ?? config('site-content.home_layout');
        $defaults = \App\Models\HomepageOfferSet::query()->get()->groupBy('row_type')->map(fn ($sets) => $sets->sortBy('id')->first()->id);
        $sections = collect($sections)->map(function ($section) use ($defaults) {
            $row = ['offerFour' => 'four', 'offerWide' => 'wide', 'offerTwo' => 'two'][$section['type']] ?? null;
            if ($row && empty($section['offer_set_id']) && isset($defaults[$row])) $section['offer_set_id'] = $defaults[$row];
            return $section;
        })->values();
        return response()->json($sections);
    }

    public function update(Request $request): JsonResponse
    {
        $admin = $request->user();
        abort_unless($admin instanceof Admin && $admin->role === 'super_admin', 403);
        $data = $request->validate([
            'sections' => ['required', 'array', 'max:30'],
            'sections.*.id' => ['required', 'string', 'max:60', 'distinct'],
            'sections.*.type' => ['required', Rule::in(['hero', 'trust', 'categories', 'trending', 'offerFour', 'offerWide', 'offerTwo', 'collection', 'reviews', 'featured', 'support'])],
            'sections.*.enabled' => ['required', 'boolean'],
            'sections.*.title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'sections.*.collection_id' => ['sometimes', 'nullable', 'integer', 'exists:product_collections,id'],
            'sections.*.offer_set_id' => ['sometimes', 'nullable', 'integer', 'exists:homepage_offer_sets,id'],
        ]);
        foreach ($data['sections'] as $index => $section) {
            if ($section['type'] === 'collection' && empty($section['collection_id'])) {
                throw ValidationException::withMessages(["sections.{$index}.collection_id" => 'Choose a collection for this section.']);
            }
            if (str_starts_with($section['type'], 'offer') && !empty($section['offer_set_id'])) {
                $set = \App\Models\HomepageOfferSet::find($section['offer_set_id']);
                $expected = ['offerFour' => 'four', 'offerWide' => 'wide', 'offerTwo' => 'two'][$section['type']];
                if (!$set || $set->row_type !== $expected) throw ValidationException::withMessages(["sections.{$index}.offer_set_id" => 'Choose a matching offer set.']);
            }
        }
        SiteSetting::updateOrCreate(['key' => 'home_layout'], ['payload' => $data['sections']]);
        return $this->show();
    }
}
