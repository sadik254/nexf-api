<?php

namespace App\Support;

use Closure;
use Illuminate\Http\UploadedFile;

/** Shared limits for files uploaded through the API, in KiB as Laravel expects. */
final class UploadFileRules
{
    public const MAX_IMAGE_KB = 10_240;
    public const MAX_VIDEO_KB = 102_400;

    private const IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    private const VIDEO_MIMES = [
        'video/mp4',
        'video/webm',
        'video/quicktime',
    ];

    /** @return array<int, string|Closure> */
    public static function media(bool $allowSvg = false): array
    {
        $mimes = self::IMAGE_MIMES;
        if ($allowSvg) {
            $mimes[] = 'image/svg+xml';
        }
        $mimes = [...$mimes, ...self::VIDEO_MIMES];

        return [
            'required',
            'file',
            'mimetypes:'.implode(',', $mimes),
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (! $value instanceof UploadedFile) {
                    return;
                }

                $mimeType = strtolower((string) $value->getMimeType());
                $maxKilobytes = in_array($mimeType, self::VIDEO_MIMES, true)
                    ? self::MAX_VIDEO_KB
                    : self::MAX_IMAGE_KB;
                if ((int) $value->getSize() > $maxKilobytes * 1024) {
                    $fail("The {$attribute} must not be greater than {$maxKilobytes} kilobytes.");
                }
            },
        ];
    }
}
