<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaUploadService
{
    public function upload(UploadedFile $file, string $directory = 'media'): string
    {
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $key = trim($directory, '/').'/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
        Storage::disk('r2')->put($key, fopen($file->getRealPath(), 'r'), [
            'visibility' => 'public',
            'ContentType' => $file->getMimeType() ?: 'application/octet-stream',
        ]);

        return $this->publicUrl($key);
    }

    public function publicUrl(string $key): string
    {
        return rtrim((string) config('filesystems.disks.r2.url'), '/').'/'.ltrim($key, '/');
    }

    public function imageVariant(?string $url, string $variant): ?string
    {
        $base = rtrim((string) config('filesystems.disks.r2.url'), '/');
        if (!$url || !$base || !str_starts_with($url, $base.'/')) return null;
        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        $options = match ($variant) {
            'card' => 'width=750,height=1000,fit=cover,format=auto,quality=85',
            'pdp_defaults' => 'height=1000,format=auto,quality=85',
            'pdp_zoom' => 'height=2000,format=auto,quality=90',
            'thumb' => 'width=256,height=256,fit=cover,format=auto,quality=80',
            default => 'format=auto,quality=85',
        };
        return $base.'/cdn-cgi/image/'.$options.'/'.$path;
    }
}
