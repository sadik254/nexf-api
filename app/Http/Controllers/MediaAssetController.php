<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\MediaAsset;
use App\Models\MediaAssetStoreLibrary;
use App\Models\Seller;
use App\Services\MediaUploadService;
use App\Services\MediaReferenceCleanupService;
use App\Support\UploadFileRules;
use enshrined\svgSanitize\Sanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MediaAssetController extends Controller
{
    public function __construct(private MediaUploadService $uploads, private MediaReferenceCleanupService $references) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $query = MediaAsset::query()->whereIn('owner_type', ['admin', 'seller'])->with('storeLibraries.seller')->latest();
        if ($actor instanceof Seller) {
            $query->where(function ($assets) use ($actor) {
                $assets->whereHas('storeLibraries', fn ($libraries) => $libraries->where('seller_id', $actor->id))
                    // Keeps pre-migration rows usable until their backfill has run.
                    ->orWhere(fn ($legacy) => $legacy->doesntHave('allStoreLibraries')->where('owner_type', 'seller')->where('owner_id', $actor->id));
            });
        }
        if ($actor instanceof Admin && $request->query('owner_type') === 'admin') {
            $query->where('owner_type', 'admin');
        }
        if ($request->filled('search')) {
            $term = substr((string) $request->query('search'), 0, 100);
            $query->where(fn ($q) => $q->where('file_name', 'like', "%{$term}%")->orWhere('alt_text', 'like', "%{$term}%"));
        }
        if ($request->filled('kind')) {
            $kind = $request->query('kind');
            if ($kind === 'image') {
                $query->where('mime_type', 'like', 'image/%');
            } elseif ($kind === 'video') {
                $query->where('mime_type', 'like', 'video/%');
            }
        }
        $page = $query->paginate(max(1, min((int) $request->query('per_page', 30), 100)));
        $page->getCollection()->transform(fn (MediaAsset $asset) => $this->resource($asset));

        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'file' => UploadFileRules::media(allowSvg: true),
            'alt_text' => ['nullable', 'string', 'max:255'],
            ...$this->libraryRules(),
        ]);
        $file = $data['file'];
        $mimeType = $this->sanitizeSvgIfNeeded($file);
        $asset = MediaAsset::create([
            'owner_type' => $actor instanceof Seller ? 'seller' : 'admin',
            'owner_id' => $actor->id,
            ...$this->uploader($request, $actor),
            'source' => 'upload',
            'url' => $this->uploads->upload($file, 'media', $mimeType),
            'file_name' => $this->nextAvailableFileName($file->getClientOriginalName()),
            'mime_type' => $mimeType,
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
            'mime_type' => ['nullable', Rule::in(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/webm', 'video/quicktime'])],
            'alt_text' => ['nullable', 'string', 'max:255'],
            ...$this->libraryRules(),
        ]);
        $extension = strtolower(pathinfo($data['file_name'], PATHINFO_EXTENSION));
        $mime = $data['mime_type'] ?? match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
            'gif' => 'image/gif', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
            default => null,
        };
        $fileName = $this->nextAvailableFileName($data['file_name']);
        $asset = MediaAsset::create([
            ...$data,
            'file_name' => $fileName,
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
            'thumbnail_time_seconds' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            ...$this->libraryRules(),
        ]);
        if (array_key_exists('file_name', $data) && $this->fileNameTaken($data['file_name'], $asset->id)) {
            throw ValidationException::withMessages([
                'file_name' => ['A file named “'.trim($data['file_name']).'” already exists.'],
            ]);
        }
        if (array_key_exists('thumbnail_time_seconds', $data) && ! $this->supportsCustomVideoThumbnail($asset)) {
            unset($data['thumbnail_time_seconds']);
        }
        $asset->update(collect($data)->only(['alt_text', 'file_name', 'thumbnail_time_seconds'])->all());
        if ($actor instanceof Admin && $request->hasAny(['seller_id', 'seller_ids', 'include_house'])) {
            $this->syncLibraries($asset, $data, $actor);
        }

        return response()->json($this->resource($asset->fresh()->load('storeLibraries.seller')));
    }

    public function replace(Request $request, MediaAsset $asset): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeAsset($actor, $asset);
        if ($asset->source === 'url') {
            throw ValidationException::withMessages(['file' => ['A linked file cannot be replaced. Upload a file instead.']]);
        }

        $data = $request->validate(['file' => UploadFileRules::media(allowSvg: true)]);
        $file = $data['file'];
        $mimeType = $this->sanitizeSvgIfNeeded($file);
        $oldIsImage = str_starts_with((string) $asset->mime_type, 'image/');
        $newIsImage = str_starts_with($mimeType, 'image/');
        if ($oldIsImage !== $newIsImage) {
            $kind = $oldIsImage ? 'photo' : 'video';
            throw ValidationException::withMessages(['file' => ["Pick a {$kind} to replace a {$kind}."]]);
        }

        $oldUrl = $asset->url;
        $newUrl = $this->uploads->upload($file, 'media', $mimeType);
        try {
            DB::transaction(function () use ($asset, $oldUrl, $newUrl, $file, $mimeType): void {
                $locked = MediaAsset::query()->lockForUpdate()->findOrFail($asset->id);
                abort_if($locked->source === 'url', 422, 'A linked file cannot be replaced. Upload a file instead.');
                $metadata = $this->metadata($file);
                $thumbnailTime = $locked->thumbnail_time_seconds;
                if ($thumbnailTime !== null && $metadata['duration_seconds'] !== null && (float) $thumbnailTime > $metadata['duration_seconds']) {
                    $thumbnailTime = null;
                }
                $locked->update([
                    'url' => $newUrl,
                    'file_name' => $this->nextAvailableFileName($file->getClientOriginalName(), $locked->id),
                    'mime_type' => $mimeType,
                    'size_bytes' => $file->getSize(),
                    ...$metadata,
                    'thumbnail_time_seconds' => $thumbnailTime,
                ]);
                $this->references->replace($oldUrl, $newUrl);
            });
        } catch (\Throwable $exception) {
            $this->deleteReplacementObject($newUrl);
            throw $exception;
        }

        $this->deleteReplacementObject($oldUrl);

        return response()->json($this->resource($asset->fresh()->load('storeLibraries.seller')));
    }

    public function destroy(Request $request, MediaAsset $asset): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeAsset($actor, $asset);
        if ($actor instanceof Seller) {
            $library = $asset->storeLibraries()->where('seller_id', $actor->id)->firstOrFail();
            $library->update(['deleted_by_name' => $this->libraryDeletedByName($request, $actor)]);
            $library->delete();

            return response()->json(['message' => 'Moved to this store\'s trash. It will be removed from the store library in 30 days.']);
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($asset, $actor): void {
            $this->references->clear($asset->url);
            $asset->update(['deleted_by_name' => $actor instanceof Seller ? $actor->seller_name : $actor->name]);
            $asset->delete();
        });

        return response()->json(['message' => 'Moved to trash. It will be permanently deleted in 30 days.']);
    }

    public function trash(Request $request): JsonResponse
    {
        $this->actor($request);
        $page = MediaAsset::onlyTrashed()->whereIn('owner_type', ['admin', 'seller'])
            ->with('storeLibraries.seller')->latest('deleted_at')
            ->paginate(max(1, min((int) $request->query('per_page', 30), 100)));
        $page->getCollection()->transform(fn (MediaAsset $asset) => $this->resource($asset) + [
            'purge_at' => $asset->deleted_at?->copy()->addDays(30)->toISOString(),
        ]);

        return response()->json($page);
    }

    public function restore(Request $request, MediaAsset $asset): JsonResponse
    {
        $this->actor($request);
        abort_unless($asset->trashed() && in_array($asset->owner_type, ['admin', 'seller'], true), 404);
        $asset->restore();
        $asset->update(['deleted_by_name' => null]);

        return response()->json($this->resource($asset->fresh()->load('storeLibraries.seller')));
    }

    public function purge(Request $request, MediaAsset $asset): JsonResponse
    {
        $this->actor($request);
        abort_unless($asset->trashed() && in_array($asset->owner_type, ['admin', 'seller'], true), 404);
        $this->purgeAsset($asset);

        return response()->json(['message' => 'Media file permanently deleted.']);
    }

    public function emptyTrash(Request $request): JsonResponse
    {
        $this->actor($request);
        $count = 0;
        MediaAsset::onlyTrashed()->whereIn('owner_type', ['admin', 'seller'])->orderBy('id')->each(function (MediaAsset $asset) use (&$count): void {
            $this->purgeAsset($asset);
            $count++;
        });

        return response()->json(['message' => "Permanently deleted {$count} media file(s).", 'count' => $count]);
    }

    public function purgeExpired(): int
    {
        $count = 0;
        MediaAsset::onlyTrashed()->whereIn('owner_type', ['admin', 'seller'])
            ->where('deleted_at', '<', now()->subDays(30))->orderBy('id')
            ->each(function (MediaAsset $asset) use (&$count): void {
                $this->purgeAsset($asset);
                $count++;
            });

        MediaAssetStoreLibrary::onlyTrashed()->where('deleted_at', '<', now()->subDays(30))->orderBy('id')
            ->each(function (MediaAssetStoreLibrary $library) use (&$count): void {
                $this->purgeStoreLibrary($library);
                $count++;
            });

        return $count;
    }

    public function sellerTrash(Request $request): JsonResponse
    {
        $seller = $this->sellerActor($request);
        $page = MediaAssetStoreLibrary::onlyTrashed()->where('seller_id', $seller->id)
            ->whereHas('asset')->with('asset.storeLibraries.seller')->latest('deleted_at')
            ->paginate(max(1, min((int) $request->query('per_page', 30), 100)));
        $page->getCollection()->transform(fn (MediaAssetStoreLibrary $library) => array_merge($this->resource($library->asset), [
            'deleted_at' => $library->deleted_at?->toISOString(),
            'purge_at' => $library->deleted_at?->copy()->addDays(30)->toISOString(),
            'deleted_by_name' => $library->deleted_by_name,
        ]));

        return response()->json($page);
    }

    public function restoreSellerLibrary(Request $request, MediaAsset $asset): JsonResponse
    {
        $seller = $this->sellerActor($request);
        $library = MediaAssetStoreLibrary::onlyTrashed()->where('media_asset_id', $asset->id)->where('seller_id', $seller->id)->firstOrFail();
        $library->restore();
        $library->update(['deleted_by_name' => null]);

        return response()->json($this->resource($asset->fresh()->load('storeLibraries.seller')));
    }

    public function purgeSellerLibrary(Request $request, MediaAsset $asset): JsonResponse
    {
        $seller = $this->sellerActor($request);
        $library = MediaAssetStoreLibrary::onlyTrashed()->where('media_asset_id', $asset->id)->where('seller_id', $seller->id)->firstOrFail();
        DB::transaction(fn () => $this->purgeStoreLibrary($library));

        return response()->json(['message' => 'Removed from this store trash.']);
    }

    public function emptySellerTrash(Request $request): JsonResponse
    {
        $seller = $this->sellerActor($request);
        $count = 0;
        DB::transaction(function () use ($seller, &$count): void {
            MediaAssetStoreLibrary::onlyTrashed()->where('seller_id', $seller->id)->orderBy('id')->each(function (MediaAssetStoreLibrary $library) use (&$count): void {
                $this->purgeStoreLibrary($library);
                $count++;
            });
        });

        return response()->json(['message' => "Removed {$count} file(s) from this store trash.", 'count' => $count]);
    }

    private function purgeAsset(MediaAsset $asset): void
    {
        $key = $this->r2Key($asset->url);
        if ($key !== null && ! \Illuminate\Support\Facades\Storage::disk('r2')->delete($key)) {
            throw new \RuntimeException('The R2 object could not be deleted. The media record was kept.');
        }
        $asset->forceDelete();
    }

    /** Permanently removes only one store's removed library link. */
    private function purgeStoreLibrary(MediaAssetStoreLibrary $library): void
    {
        $asset = MediaAsset::withTrashed()->find($library->media_asset_id);
        $deletedBy = $library->deleted_by_name;
        $library->forceDelete();
        if (! $asset || $asset->trashed()) return;

        // This runs only at the moment a store drops its final link. A
        // deliberately linkless Site-library file is never swept up later.
        if (! MediaAssetStoreLibrary::withTrashed()->where('media_asset_id', $asset->id)->exists()
            && ! $this->references->isReferenced($asset->url)) {
            $asset->update(['deleted_by_name' => $deletedBy]);
            $asset->delete();
        }
    }

    private function r2Key(string $url): ?string
    {
        $base = rtrim((string) config('filesystems.disks.r2.url'), '/');
        if ($base === '' || ! str_starts_with($url, $base.'/')) {
            return null;
        }

        return ltrim((string) parse_url($url, PHP_URL_PATH), '/');
    }

    /** R2 cleanup must never make a completed database replacement fail. */
    private function deleteReplacementObject(string $url): void
    {
        $key = $this->uploads->r2Key($url);
        if ($key === null) return;
        try {
            if (! Storage::disk('r2')->delete($key)) {
                Log::warning('Could not delete replaced media object from R2.', ['url' => $url]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Could not delete replaced media object from R2.', ['url' => $url, 'exception' => $exception->getMessage()]);
        }
    }

    private function metadata(UploadedFile $file): array
    {
        $meta = ['width' => null, 'height' => null, 'duration_seconds' => null];
        if (str_starts_with((string) $file->getMimeType(), 'image/')) {
            $size = @getimagesize($file->getPathname());
            if ($size) {
                $meta['width'] = $size[0];
                $meta['height'] = $size[1];
            }
        }
        if (str_starts_with((string) $file->getMimeType(), 'video/') && function_exists('shell_exec')) {
            $value = @shell_exec('ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '.escapeshellarg($file->getPathname()).' 2>/dev/null');
            if (is_numeric(trim((string) $value))) {
                $meta['duration_seconds'] = (int) round((float) $value);
            }
        }

        return $meta;
    }

    /** Console-library names are global labels; customer review uploads are excluded. */
    private function nextAvailableFileName(string $fileName, ?int $exceptId = null): string
    {
        $fileName = trim($fileName);
        $candidate = $fileName;
        for ($number = 2; $this->fileNameTaken($candidate, $exceptId); $number++) {
            $extension = pathinfo($fileName, PATHINFO_EXTENSION);
            $suffix = '-'.$number;
            $extensionSuffix = $extension === '' ? '' : '.'.$extension;
            $base = pathinfo($fileName, PATHINFO_FILENAME);
            $available = max(1, 255 - strlen($suffix.$extensionSuffix));
            $candidate = mb_strimwidth($base, 0, $available, '').$suffix.$extensionSuffix;
        }

        return $candidate;
    }

    private function fileNameTaken(string $fileName, ?int $exceptId = null): bool
    {
        return MediaAsset::query()
            ->whereIn('owner_type', ['admin', 'seller'])
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->whereRaw('LOWER(file_name) = ?', [mb_strtolower(trim($fileName))])
            ->exists();
    }

    /** SVG is active XML, so clean it before it ever reaches the public media host. */
    private function sanitizeSvgIfNeeded(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType() ?: 'application/octet-stream';
        if ($mimeType !== 'image/svg+xml' && $extension !== 'svg') {
            return $mimeType;
        }

        $dirty = @file_get_contents($file->getRealPath());
        $sanitizer = new Sanitizer;
        $sanitizer->removeRemoteReferences(true);
        $clean = is_string($dirty) ? $sanitizer->sanitize($dirty) : false;
        if ($clean === false || trim($clean) === '') {
            throw ValidationException::withMessages(['file' => ['The SVG file could not be safely processed.']]);
        }
        file_put_contents($file->getRealPath(), $clean, LOCK_EX);

        return 'image/svg+xml';
    }

    private function actor(Request $request): Admin|Seller
    {
        $actor = $request->user();
        abort_unless($actor instanceof Admin || $actor instanceof Seller, 403);

        return $actor;
    }

    private function sellerActor(Request $request): Seller
    {
        $actor = $this->actor($request);
        abort_unless($actor instanceof Seller, 403);

        return $actor;
    }

    private function libraryDeletedByName(Request $request, Seller $seller): string
    {
        return $request->attributes->get('seller_staff')?->name ?? $seller->seller_name;
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
            if (array_key_exists('seller_id', $data) && $data['seller_id'] !== null) {
                $sellerIds[] = (int) $data['seller_id'];
            }
            $sellerIds = array_values(array_unique(array_map('intval', $sellerIds)));
            // Existing admin uploads without a target remain in NEXF's library.
            $includeHouse = array_key_exists('include_house', $data) ? (bool) $data['include_house'] : $sellerIds === [];
        }

        MediaAssetStoreLibrary::withTrashed()->where('media_asset_id', $asset->id)->forceDelete();
        $rows = collect($sellerIds)->map(fn (int $id) => ['seller_id' => $id, 'library_key' => "seller:{$id}"]);
        if ($includeHouse) {
            $rows->push(['seller_id' => null, 'library_key' => 'house']);
        }
        $asset->storeLibraries()->createMany($rows->all());
    }

    private function uploader(Request $request, Admin|Seller $actor): array
    {
        $staff = $request->attributes->get('seller_staff');
        if ($staff) {
            return ['uploaded_by_type' => 'seller_staff', 'uploaded_by_id' => $staff->id, 'uploader_name' => $staff->name];
        }

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
            'thumbnail_time_seconds' => $asset->thumbnail_time_seconds === null ? null : (float) $asset->thumbnail_time_seconds,
            'seller_ids' => collect($libraries)->pluck('seller_id')->filter()->map(fn ($id) => (int) $id)->values()->all(),
            'include_house' => collect($libraries)->contains('type', 'house'),
            'libraries' => $libraries,
        ]);
    }

    private function supportsCustomVideoThumbnail(MediaAsset $asset): bool
    {
        if (! str_starts_with((string) $asset->mime_type, 'video/')) return false;
        if ($asset->source !== 'url') return true;

        $host = strtolower((string) parse_url($asset->url, PHP_URL_HOST));

        return ! str_contains($host, 'youtube.com')
            && ! str_contains($host, 'youtu.be')
            && ! str_contains($host, 'vimeo.com');
    }
}
