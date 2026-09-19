<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\HomepageBanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Uploadcare\Api;
use Uploadcare\Configuration;

class HomepageBannerController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(HomepageBanner::query()->where('is_active', true)->orderBy('placement')->orderBy('sort_order')->get());
    }

    public function indexAdmin(): JsonResponse
    {
        return response()->json(HomepageBanner::query()->orderBy('placement')->orderBy('sort_order')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $this->validated($request, true);
        $data['image'] = $this->upload($request->file('image'));
        $banner = HomepageBanner::create($data);
        return response()->json(['message' => 'Homepage banner created.', 'banner' => $banner], 201);
    }

    public function update(Request $request, HomepageBanner $homepageBanner): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $this->validated($request, false);
        if ($request->hasFile('image')) $data['image'] = $this->upload($request->file('image'));
        $homepageBanner->fill($data)->save();
        return response()->json(['message' => 'Homepage banner updated.', 'banner' => $homepageBanner]);
    }

    public function destroy(Request $request, HomepageBanner $homepageBanner): JsonResponse
    {
        $this->authorizeAdmin($request);
        $homepageBanner->delete();
        return response()->json(['message' => 'Homepage banner deleted.']);
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'placement' => [$creating ? 'required' : 'sometimes', 'in:hero,side_top,side_bottom'],
            'image' => [$creating ? 'required' : 'sometimes', 'image', 'max:5120'],
            'href' => ['sometimes', 'string', 'max:2048'],
            'title' => ['sometimes', 'nullable', 'string', 'max:100'],
            'emphasis' => ['sometimes', 'nullable', 'string', 'max:100'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:160'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user() instanceof Admin && $request->user()->role === 'super_admin', 403, 'Forbidden.');
    }

    private function upload($file): string
    {
        $api = new Api(Configuration::create(config('services.uploadcare.public_key'), config('services.uploadcare.secret_key')));
        $uploaded = $api->uploader()->fromPath($file->getPathname());
        return "https://ucarecdn.com/{$uploaded->getUuid()}/-/preview/";
    }
}
