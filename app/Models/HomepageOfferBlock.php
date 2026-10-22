<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomepageOfferBlock extends Model
{

    protected $appends = ['original_image'];

    public function getOriginalImageAttribute(): ?string
    {
        $image = $this->attributes['image'] ?? null;
        if (!$image || !preg_match('~^https://ucarecdn\.com/([a-f0-9-]{36})(?:/.*)?$~i', $image, $matches)) return $image;
        return 'https://ucarecdn.com/'.$matches[1].'/';
    }
    protected $fillable = ['offer_set_id', 'row_type', 'slot', 'title', 'body', 'cta', 'href', 'image', 'mobile_image', 'theme', 'hidden', 'coupon_id', 'expires_at', 'store_name'];
    protected function casts(): array { return ['slot' => 'integer', 'hidden' => 'boolean', 'expires_at' => 'date']; }
}
