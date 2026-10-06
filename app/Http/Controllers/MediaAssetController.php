<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\MediaAsset;
use App\Models\Seller;
use App\Services\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MediaAssetController extends Controller
{
    public function __construct(private MediaUploadService $uploads) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $query = MediaAsset::query()->latest();
        if ($actor instanceof Seller) $query->where('owner_type', 'seller')->where('owner_id', $actor->id);
        if ($actor instanceof Admin && $request->query('owner_type') === 'admin') $query->where('owner_type', 'admin');
        if ($request->filled('search')) {
            $term = substr((string) $request->query('search'), 0, 100);
            $query->where(fn ($q) => $q->where('file_name', 'like', "%{$term}%")->orWhere('alt_text', 'like', "%{$term}%"));
        }
        if ($request->filled('kind')) {
            $kind = $request->query('kind');
            if ($kind === 'image') $query->where('mime_type', 'like', 'image/%');
            elseif ($kind === 'video') $query->where('mime_type', 'like', 'video/%');
        }
        return response()->json($query->paginate(max(1, min((int) $request->query('per_page', 30), 100))));
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm', 'max:20480'],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ]);
        $file = $data['file'];
        $asset = MediaAsset::create([
            'owner_type' => $actor instanceof Seller ? 'seller' : 'admin',
            'owner_id' => $actor->id,
            'source' => 'upload',
            'url' => $this->uploads->upload($file),
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'alt_text' => $data['alt_text'] ?? null,
        ]);
        return response()->json($asset, 201);
    }

    public function link(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'url' => ['required', 'url:http,https', 'max:2048'],
            'file_name' => ['required', 'string', 'max:255'],
            'mime_type' => ['nullable', Rule::in(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/webm'])],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ]);
        $extension = strtolower(pathinfo($data['file_name'], PATHINFO_EXTENSION));
        $mime = $data['mime_type'] ?? match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
            'gif' => 'image/gif', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
            default => null,
        };
        $asset = MediaAsset::create([
            ...$data,
            'mime_type' => $mime,
            'owner_type' => $actor instanceof Seller ? 'seller' : 'admin',
            'owner_id' => $actor->id,
            'source' => 'url',
        ]);
        return response()->json($asset, 201);
    }

    public function update(Request $request, MediaAsset $asset): JsonResponse
    {
        $this->authorizeAsset($this->actor($request), $asset);
        $data = $request->validate(['alt_text' => ['nullable', 'string', 'max:255'], 'file_name' => ['sometimes', 'required', 'string', 'max:255']]);
        $asset->update($data);
        return response()->json($asset->fresh());
    }

    public function destroy(Request $request, MediaAsset $asset): JsonResponse
    {
        $this->authorizeAsset($this->actor($request), $asset);
        $asset->delete();
        return response()->json(['message' => 'Removed from the media library. The original file remains available at its URL.']);
    }

    private function actor(Request $request): Admin|Seller
    {
        $actor = $request->user();
        abort_unless($actor instanceof Admin || $actor instanceof Seller, 403);
        return $actor;
    }

    private function authorizeAsset(Admin|Seller $actor, MediaAsset $asset): void
    {
        abort_if($actor instanceof Seller && ($asset->owner_type !== 'seller' || $asset->owner_id !== $actor->id), 404);
    }
}
