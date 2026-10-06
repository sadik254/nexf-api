<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\HomepageCategoryCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HomepageCategoryCardController extends Controller
{
    public function publicIndex(): JsonResponse
    {
        return response()->json(HomepageCategoryCard::with('category:id,name,slug,is_active')
            ->where('hidden', false)->orderBy('sort_order')->get()
            ->filter(fn ($card) => $card->category?->is_active ?? true)->values());
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeManager($request);
        return response()->json(HomepageCategoryCard::with('category:id,name,slug,is_active')->orderBy('sort_order')->get());
    }

    public function replace(Request $request): JsonResponse
    {
        $this->authorizeManager($request);
        $data = $request->validate([
            'cards' => ['required', 'array', 'max:30'],
            'cards.*.id' => ['sometimes', 'integer', 'exists:homepage_category_cards,id'],
            'cards.*.category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'cards.*.name' => ['required', 'string', 'max:120'],
            'cards.*.caption' => ['nullable', 'string', 'max:120'],
            'cards.*.href' => ['required', 'string', 'max:500', 'regex:/^(\/|https:\/\/)/'],
            'cards.*.image' => ['required', 'string', 'max:1000', 'regex:/^(\/|https:\/\/)/'],
            'cards.*.mobile_image' => ['nullable', 'string', 'max:1000', 'regex:/^(\/|https:\/\/)/'],
            'cards.*.cta' => ['nullable', 'string', 'max:80'],
            'cards.*.theme' => ['required', Rule::in(['slate', 'gray', 'zinc', 'neutral', 'stone', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose'])],
            'cards.*.hidden' => ['required', 'boolean'],
        ]);
        $ids = array_values(array_filter(array_column($data['cards'], 'id')));
        abort_if(count($ids) !== count(array_unique($ids)), 422, 'Duplicate card ID.');
        DB::transaction(function () use ($data, $ids) {
            HomepageCategoryCard::whereNotIn('id', $ids)->delete();
            foreach ($data['cards'] as $order => $card) {
                $id = $card['id'] ?? null;
                unset($card['id']);
                HomepageCategoryCard::updateOrCreate(['id' => $id], [...$card, 'sort_order' => $order]);
            }
        });
        return $this->index($request);
    }

    private function authorizeManager(Request $request): void
    {
        $admin = $request->user();
        abort_unless($admin instanceof Admin && $admin->role === 'super_admin', 403);
    }
}
