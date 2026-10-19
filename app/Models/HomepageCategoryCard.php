<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomepageCategoryCard extends Model
{

    protected $appends = ['original_image'];

    public function getOriginalImageAttribute(): ?string
    {
        $image = $this->attributes['image'] ?? null;
        if (!$image || !preg_match('~^https://ucarecdn\.com/([a-f0-9-]{36})(?:/.*)?$~i', $image, $matches)) return $image;
        return 'https://ucarecdn.com/'.$matches[1].'/';
    }
    protected $fillable = ['category_id', 'name', 'caption', 'href', 'image', 'mobile_image', 'cta', 'theme', 'hidden', 'sort_order'];

    protected function casts(): array { return ['hidden' => 'boolean', 'sort_order' => 'integer']; }

    public function category(): BelongsTo { return $this->belongsTo(ProductCategory::class, 'category_id'); }
}
