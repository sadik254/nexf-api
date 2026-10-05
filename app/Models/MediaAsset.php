<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaAsset extends Model
{
    protected $fillable = ['owner_type', 'owner_id', 'source', 'url', 'file_name', 'mime_type', 'size_bytes', 'alt_text'];
    protected function casts(): array { return ['size_bytes' => 'integer']; }
}
