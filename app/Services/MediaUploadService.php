<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Uploadcare\Api;
use Uploadcare\Configuration;

class MediaUploadService
{
    public function upload(UploadedFile $file): string
    {
        $api = new Api(Configuration::create(config('services.uploadcare.public_key'), config('services.uploadcare.secret_key')));
        $uploaded = $api->uploader()->fromPath($file->getPathname());
        return "https://ucarecdn.com/{$uploaded->getUuid()}/-/preview/";
    }
}
