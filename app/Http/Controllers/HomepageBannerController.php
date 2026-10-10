<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\HomepageBanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Uploadcare\Api;
use Uploadcare\Configuration;

class HomepageBannerController extends Controller
{
    public function index(): JsonResponse
    {
        $hero = HomepageBanner::where('placement', 'hero')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $side = HomepageBanner::where('placement', '!=', 'hero')->orderBy('sort_order')->orderBy('id')->limit(2)->get();
        return response()->json($hero->concat($side)->values());
    }

    public function indexAdmin(): JsonResponse
    {
        return response()->json(HomepageBanner::query()->orderBy('sort_order')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $this->validated($request, true);
        if ($request->hasFile('image')) $data['image'] = $this->upload($request->file('image'));
        $banner = HomepageBanner::create($data);
        return response()->json(['message' => 'Homepage banner created.', 'banner' => $banner], 201);
    }

    public function update(Request $request, HomepageBanner $homepageBanner): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $this->validated($request, false);
        $this->assertHeroRemainsVisible($homepageBanner, $data, false);
        if ($request->hasFile('image')) $data['image'] = $this->upload($request->file('image'));
        $homepageBanner->fill($data)->save();
        return response()->json(['message' => 'Homepage banner updated.', 'banner' => $homepageBanner]);
    }

    public function destroy(Request $request, HomepageBanner $homepageBanner): JsonResponse
    {
        $this->authorizeAdmin($request);
        $this->assertHeroRemainsVisible($homepageBanner, [], true);
        if ($homepageBanner->placement !== 'hero' && HomepageBanner::where('placement', '!=', 'hero')->count() <= 2) {
            throw ValidationException::withMessages(['banner' => 'The homepage needs two side banners.']);
        }
        $homepageBanner->delete();
        return response()->json(['message' => 'Homepage banner deleted.']);
    }

    public function reorder(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['group' => ['required', 'in:hero,side'], 'ids' => ['required', 'array'], 'ids.*' => ['required', 'integer', 'distinct']]);
        $rows = \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            $rows = HomepageBanner::query()->where('placement', $data['group'] === 'hero' ? '=' : '!=', 'hero')->lockForUpdate()->get();
            $expected = $rows->pluck('id')->sort()->values()->all();
            $supplied = collect($data['ids'])->map(fn ($id) => (int) $id)->sort()->values()->all();
            if ($expected !== $supplied) throw ValidationException::withMessages(['ids' => 'The banner list has changed. Reload and try again.']);
            foreach ($data['ids'] as $position => $id) $rows->firstWhere('id', $id)->update(['sort_order' => $position]);
            return $rows->sortBy('sort_order')->values();
        });
        return response()->json($rows);
    }

    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'placement' => [$creating ? 'required' : 'sometimes', 'in:hero,side_top,side_bottom'],
            'image' => [$creating ? 'required' : 'sometimes'],
            'cta' => ['sometimes', 'nullable', 'string', 'max:100'],
            'theme' => ['sometimes', 'nullable', \Illuminate\Validation\Rule::in(['slate','gray','zinc','neutral','stone','red','orange','amber','yellow','lime','green','emerald','teal','cyan','sky','blue','indigo','violet','purple','fuchsia','pink','rose'])],
            'href' => ['sometimes', 'string', 'max:2048'],
            'title' => ['sometimes', 'nullable', 'string', 'max:100'],
            'emphasis' => ['sometimes', 'nullable', 'string', 'max:100'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:160'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
        if ($request->hasFile('image')) {
            $request->validate(['image' => ['file', 'image', 'max:5120']]);
        } elseif (isset($data['image'])) {
            $request->validate(['image' => ['string', 'url', 'max:2048', 'regex:/^https:\/\//']]);
            $asset = \App\Models\MediaAsset::where('url', $data['image'])->where('owner_type', 'admin')->where('owner_id', $request->user()->id)->first();
            $existing = $request->route('homepageBanner');
            if (!$asset && (!$existing || $existing->image !== $data['image'])) {
                throw ValidationException::withMessages(['image' => 'Choose an image from your media library.']);
            }
        }
        if (isset($data['href']) && !(str_starts_with($data['href'], '/') && !str_starts_with($data['href'], '//')) && !preg_match('~^https?://[^\s]+$~i', $data['href'])) {
            throw ValidationException::withMessages(['href' => 'Use an internal path or HTTP/HTTPS link.']);
        }
        return $data;
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user() instanceof Admin && $request->user()->role === 'super_admin', 403, 'Forbidden.');
    }

    /** The storefront slider must always retain an active slide. */
    private function assertHeroRemainsVisible(HomepageBanner $banner, array $data, bool $deleting): void
    {
        if ($banner->placement !== 'hero' || !$banner->is_active || (!$deleting && ($data['is_active'] ?? true) && ($data['placement'] ?? $banner->placement) === 'hero')) return;
        $otherActive = HomepageBanner::query()->where('placement', 'hero')->where('is_active', true)->whereKeyNot($banner->id)->exists();
        if (!$otherActive) throw ValidationException::withMessages(['is_active' => ['The hero slider needs at least one visible banner.']]);
    }

    private function upload($file): string
    {
        $api = new Api(Configuration::create(config('services.uploadcare.public_key'), config('services.uploadcare.secret_key')));
        $uploaded = $api->uploader()->fromPath($file->getPathname());
        return "https://ucarecdn.com/{$uploaded->getUuid()}/-/preview/";
    }
}
