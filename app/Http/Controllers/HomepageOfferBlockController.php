<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\HomepageOfferBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HomepageOfferBlockController extends Controller
{
    public function publicIndex(): JsonResponse
    {
        return response()->json(HomepageOfferBlock::where('hidden', false)->orderByRaw("CASE row_type WHEN 'four' THEN 0 WHEN 'wide' THEN 1 ELSE 2 END")->orderBy('slot')->get());
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeManager($request);
        return response()->json(HomepageOfferBlock::orderByRaw("CASE row_type WHEN 'four' THEN 0 WHEN 'wide' THEN 1 ELSE 2 END")->orderBy('slot')->get());
    }

    public function update(Request $request, HomepageOfferBlock $block): JsonResponse
    {
        $this->authorizeManager($request);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'body' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'cta' => ['sometimes', 'required', 'string', 'max:80'],
            'href' => ['sometimes', 'required', 'string', 'max:500', 'regex:/^(\/|https:\/\/)/'],
            'image' => ['sometimes', 'required', 'string', 'max:1000', 'regex:/^(\/|https:\/\/)/'],
            'mobile_image' => ['sometimes', 'nullable', 'string', 'max:1000', 'regex:/^(\/|https:\/\/)/'],
            'theme' => ['sometimes', Rule::in(['blue', 'pink', 'violet', 'emerald', 'amber', 'sky'])],
            'hidden' => ['sometimes', 'boolean'],
        ]);
        $block->update($data);
        return response()->json($block->fresh());
    }

    private function authorizeManager(Request $request): void
    {
        $admin = $request->user();
        abort_unless($admin instanceof Admin && $admin->role === 'super_admin', 403);
    }
}
