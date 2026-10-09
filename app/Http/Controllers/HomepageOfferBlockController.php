<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\HomepageOfferBlock;
use App\Models\HomepageOfferSet;
use App\Models\SiteSetting;
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
            'theme' => ['sometimes', Rule::in(['slate', 'gray', 'zinc', 'neutral', 'stone', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose'])],
            'hidden' => ['sometimes', 'boolean'],
        ]);
        $block->update($data);
        return response()->json($block->fresh());
    }

    public function sets(Request $request): JsonResponse { $this->authorizeManager($request); return response()->json(HomepageOfferSet::with(['blocks' => fn ($q) => $q->orderBy('slot')])->orderBy('row_type')->latest()->get()); }
    public function storeSet(Request $request): JsonResponse
    {
        $this->authorizeManager($request); $data=$request->validate(['row_type'=>['required',Rule::in(['four','wide','two'])],'name'=>['required','string','max:120'],'copy_from_id'=>['nullable','integer','exists:homepage_offer_sets,id']]);
        $set=HomepageOfferSet::create(['row_type'=>$data['row_type'],'name'=>$data['name']]);
        $source=!empty($data['copy_from_id'])?HomepageOfferSet::with('blocks')->findOrFail($data['copy_from_id']):null;
        foreach (($source?->blocks ?? []) as $block) $set->blocks()->create($block->only(['row_type','slot','title','body','cta','href','image','mobile_image','theme','hidden']));
        return response()->json($set->load('blocks'),201);
    }
    public function updateSet(Request $request, HomepageOfferSet $offerSet): JsonResponse { $this->authorizeManager($request); $offerSet->update($request->validate(['name'=>['required','string','max:120']])); return response()->json($offerSet->load('blocks')); }
    public function destroySet(Request $request, HomepageOfferSet $offerSet): JsonResponse
    {
        $this->authorizeManager($request);
        $layout = SiteSetting::find('home_layout')?->payload ?? [];
        if (collect($layout)->contains(fn ($section) => (int) ($section['offer_set_id'] ?? 0) === $offerSet->id)) {
            return response()->json(['message' => 'This offer set is used by the homepage layout. Choose another set there before deleting it.'], 422);
        }
        $offerSet->delete();
        return response()->json(['message'=>'Offer set deleted.']);
    }

    private function authorizeManager(Request $request): void
    {
        $admin = $request->user();
        abort_unless($admin instanceof Admin && $admin->role === 'super_admin', 403);
    }
}
