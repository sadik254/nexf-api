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
        return response()->json(SiteSetting::find('home_layout')?->payload ?? config('site-content.home_layout'));
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
        ]);
        foreach ($data['sections'] as $index => $section) {
            if ($section['type'] === 'collection' && empty($section['collection_id'])) {
                throw ValidationException::withMessages(["sections.{$index}.collection_id" => 'Choose a collection for this section.']);
            }
        }
        SiteSetting::updateOrCreate(['key' => 'home_layout'], ['payload' => $data['sections']]);
        return $this->show();
    }
}
