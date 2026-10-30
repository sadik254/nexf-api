<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\MediaAsset;
use App\Models\MediaAssetStoreLibrary;
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
        $query = MediaAsset::query()->whereIn('owner_type', ['admin', 'seller'])->with('storeLibraries.seller')->latest();
        if ($actor instanceof Seller) {
            $query->where(function ($assets) use ($actor) {
                $assets->whereHas('storeLibraries', fn ($libraries) => $libraries->where('seller_id', $actor->id))
                    // Keeps pre-migration rows usable until their backfill has run.
                    ->orWhere(fn ($legacy) => $legacy->doesntHave('storeLibraries')->where('owner_type', 'seller')->where('owner_id', $actor->id));
            });
        }
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
        $page = $query->paginate(max(1, min((int) $request->query('per_page', 30), 100)));
        $page->getCollection()->transform(fn (MediaAsset $asset) => $this->resource($asset));
        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm', 'max:20480'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            ...$this->libraryRules(),
        ]);
        $file = $data['file'];
        $asset = MediaAsset::create([
            'owner_type' => $actor instanceof Seller ? 'seller' : 'admin',
            'owner_id' => $actor->id,
            ...$this->uploader($request, $actor),
            'source' => 'upload',
            'url' => $this->uploads->upload($file),
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'alt_text' => $data['alt_text'] ?? null,
            ...$this->metadata($file),
        ]);
        $this->syncLibraries($asset, $data, $actor);
        return response()->json($this->resource($asset->fresh()->load('storeLibraries.seller')), 201);
    }

    public function link(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'url' => ['required', 'url:http,https', 'max:2048'],
            'file_name' => ['required', 'string', 'max:255'],
            'mime_type' => ['nullable', Rule::in(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/webm'])],
            'alt_text' => ['nullable', 'string', 'max:255'],
            ...$this->libraryRules(),
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
            ...$this->uploader($request, $actor),
            'source' => 'url',
        ]);
        $this->syncLibraries($asset, $data, $actor);
        return response()->json($this->resource($asset->fresh()->load('storeLibraries.seller')), 201);
    }

    public function update(Request $request, MediaAsset $asset): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeAsset($actor, $asset);
        $data = $request->validate([
            'alt_text' => ['nullable', 'string', 'max:255'],
            'file_name' => ['sometimes', 'required', 'string', 'max:255'],
            ...$this->libraryRules(),
        ]);
        $asset->update(collect($data)->only(['alt_text', 'file_name'])->all());
        if ($actor instanceof Admin && $request->hasAny(['seller_id', 'seller_ids', 'include_house'])) $this->syncLibraries($asset, $data, $actor);
        return response()->json($this->resource($asset->fresh()->load('storeLibraries.seller')));
    }

    public function destroy(Request $request, MediaAsset $asset): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeAsset($actor, $asset);
        if ($actor instanceof Seller) {
            $asset->storeLibraries()->where('seller_id', $actor->id)->delete();
            return response()->json(['message' => 'Removed from this store media library. The original file remains available for other stores.']);
        }
        $asset->delete();
        return response()->json(['message' => 'Removed from every media library. The original file remains available at its URL.']);
    }

    private function metadata(\Illuminate\Http\UploadedFile $file): array
    {
        $meta = ['width'=>null, 'height'=>null, 'duration_seconds'=>null];
        if (str_starts_with((string) $file->getMimeType(), 'image/')) { $size = @getimagesize($file->getPathname()); if ($size) { $meta['width']=$size[0]; $meta['height']=$size[1]; } }
        if (str_starts_with((string) $file->getMimeType(), 'video/') && function_exists('shell_exec')) { $value = @shell_exec('ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '.escapeshellarg($file->getPathname()).' 2>/dev/null'); if (is_numeric(trim((string)$value))) $meta['duration_seconds']=(int)round((float)$value); }
        return $meta;
    }

    private function actor(Request $request): Admin|Seller
    {
        $actor = $request->user();
        abort_unless($actor instanceof Admin || $actor instanceof Seller, 403);
        return $actor;
    }

    private function authorizeAsset(Admin|Seller $actor, MediaAsset $asset): void
    {
        abort_if($asset->owner_type === 'customer', 404);
        abort_if($actor instanceof Seller && ! $asset->isInSellerLibrary($actor->id), 404);
    }

    private function libraryRules(): array
    {
        return [
            // `seller_id` remains accepted for the existing console. New
            // clients use seller_ids for a file shared by several stores.
            'seller_id' => ['nullable', 'integer', 'exists:sellers,id'],
            'seller_ids' => ['sometimes', 'array', 'max:100'],
            'seller_ids.*' => ['integer', 'distinct', 'exists:sellers,id'],
            'include_house' => ['sometimes', 'boolean'],
        ];
    }

    private function syncLibraries(MediaAsset $asset, array $data, Admin|Seller $actor): void
    {
        if ($actor instanceof Seller) {
            $sellerIds = [$actor->id];
            $includeHouse = false;
        } else {
            $sellerIds = $data['seller_ids'] ?? [];
            if (array_key_exists('seller_id', $data) && $data['seller_id'] !== null) $sellerIds[] = (int) $data['seller_id'];
            $sellerIds = array_values(array_unique(array_map('intval', $sellerIds)));
            // Existing admin uploads without a target remain in NEXF's library.
            $includeHouse = array_key_exists('include_house', $data) ? (bool) $data['include_house'] : $sellerIds === [];
        }

        $asset->storeLibraries()->delete();
        $rows = collect($sellerIds)->map(fn (int $id) => ['seller_id' => $id, 'library_key' => "seller:{$id}"]);
        if ($includeHouse) $rows->push(['seller_id' => null, 'library_key' => 'house']);
        $asset->storeLibraries()->createMany($rows->all());
    }

    private function uploader(Request $request, Admin|Seller $actor): array
    {
        $staff = $request->attributes->get('seller_staff');
        if ($staff) return ['uploaded_by_type' => 'seller_staff', 'uploaded_by_id' => $staff->id, 'uploader_name' => $staff->name];
        return [
            'uploaded_by_type' => $actor instanceof Seller ? 'seller' : 'admin',
            'uploaded_by_id' => $actor->id,
            'uploader_name' => $actor instanceof Seller ? $actor->seller_name : $actor->name,
        ];
    }

    private function resource(MediaAsset $asset): array
    {
        $libraries = $asset->storeLibraries->map(fn (MediaAssetStoreLibrary $library) => [
            'seller_id' => $library->seller_id,
            'type' => $library->library_key === 'house' ? 'house' : 'seller',
            'store_name' => $library->seller?->store_name ?? 'NEXF Lifestyle',
            'store_slug' => $library->seller?->store_slug ?? 'admin',
        ])->values()->all();
        return array_merge($asset->toArray(), [
            'seller_ids' => collect($libraries)->pluck('seller_id')->filter()->map(fn ($id) => (int) $id)->values()->all(),
            'include_house' => collect($libraries)->contains('type', 'house'),
            'libraries' => $libraries,
        ]);
    }
}
