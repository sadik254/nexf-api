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
        if (!$source) {
            $assets = ['four' => [1, 2, 3, 4], 'wide' => [5], 'two' => [6, 7]][$set->row_type];
            foreach ($assets as $slot => $asset) {
                $set->blocks()->create(['row_type' => $set->row_type, 'slot' => $slot, 'title' => 'New offer', 'body' => null, 'cta' => 'Shop now', 'href' => '/shop', 'image' => '/assets/offer/'.$asset.'.png', 'mobile_image' => '/assets/offer/'.$asset.'m.png', 'theme' => 'blue', 'hidden' => true]);
            }
        }
        return response()->json($set->load('blocks'),201);
    }
    public function updateSet(Request $request, HomepageOfferSet $offerSet): JsonResponse { $this->authorizeManager($request); $offerSet->update($request->validate(['name'=>['required','string','max:120']])); return response()->json($offerSet->load('blocks')); }
    public function storeBlock(Request $request, HomepageOfferSet $offerSet): JsonResponse
    {
        $this->authorizeManager($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'], 'body' => ['nullable', 'string', 'max:1000'],
            'cta' => ['required', 'string', 'max:80'], 'href' => ['required', 'string', 'max:500', 'regex:/^(\/|https:\/\/)/'],
            'image' => ['required', 'string', 'max:1000', 'regex:/^(\/|https:\/\/)/'],
            'mobile_image' => ['nullable', 'string', 'max:1000', 'regex:/^(\/|https:\/\/)/'],
            'theme' => ['required', Rule::in(['slate','gray','zinc','neutral','stone','red','orange','amber','yellow','lime','green','emerald','teal','cyan','sky','blue','indigo','violet','purple','fuchsia','pink','rose'])],
            'hidden' => ['sometimes', 'boolean'],
        ]);
        $block = \Illuminate\Support\Facades\DB::transaction(function () use ($offerSet, $data) {
            $locked = HomepageOfferSet::query()->lockForUpdate()->findOrFail($offerSet->id);
            $slot = ((int) $locked->blocks()->max('slot')) + 1;
            if ($slot > 255) abort(422, 'This offer set cannot contain more blocks.');
            return $locked->blocks()->create($data + [
                'row_type' => $locked->row_type, 'slot' => $slot, 'hidden' => true,
            ]);
        });
        return response()->json(['block' => $block], 201);
    }

    public function destroyBlock(Request $request, HomepageOfferSet $offerSet, HomepageOfferBlock $block): JsonResponse
    {
        $this->authorizeManager($request);
        abort_unless((int) $block->offer_set_id === (int) $offerSet->id, 404);
        $block->delete();
        return response()->json(['message' => 'Offer block deleted.']);
    }
    public function destroySet(Request $request, HomepageOfferSet $offerSet): JsonResponse
    {
        $this->authorizeManager($request);
        $layout = SiteSetting::find('home_layout')?->payload ?? config('site-content.home_layout');
        $firstForType = HomepageOfferSet::query()->where('row_type', $offerSet->row_type)->orderBy('id')->value('id');
        $typeForRow = ['four' => 'offerFour', 'wide' => 'offerWide', 'two' => 'offerTwo'][$offerSet->row_type];
        if (collect($layout)->contains(fn ($section) => (int) ($section['offer_set_id'] ?? 0) === $offerSet->id || (($section['type'] ?? null) === $typeForRow && empty($section['offer_set_id']) && (int) $firstForType === $offerSet->id))) {
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
