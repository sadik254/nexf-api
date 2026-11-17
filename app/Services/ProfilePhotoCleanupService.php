<?php

namespace App\Services;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/** Removes orphaned direct-upload account photos after their database update. */
class ProfilePhotoCleanupService
{
    public function __construct(
        private MediaUploadService $uploads,
        private MediaReferenceCleanupService $references,
    ) {}

    public function deleteIfOrphaned(?string $oldUrl, ?string $newUrl): void
    {
        if (! $oldUrl || $oldUrl === $newUrl) return;
        $key = $this->uploads->r2Key($oldUrl);
        if ($key === null) return;

        // Library files belong to Media, including an item currently in its
        // admin trash. Profile updates must never delete them directly.
        if (MediaAsset::withTrashed()->where('url', $oldUrl)->exists()) return;
        if ($this->references->isReferenced($oldUrl)) return;

        try {
            if (! Storage::disk('r2')->delete($key)) {
                Log::warning('Could not delete replaced account profile photo from R2.', ['url' => $oldUrl]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Could not delete replaced account profile photo from R2.', [
                'url' => $oldUrl,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
