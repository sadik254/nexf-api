<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategory extends Model
{

    protected $appends = ['original_image', 'original_promo_image'];

    public function getOriginalImageAttribute(): ?string
    {
        return $this->attributes['image'] ?? null;
    }

    public function getOriginalPromoImageAttribute(): ?string
    {
        return $this->attributes['promo_image'] ?? null;
    }
    protected $fillable = [
        'parent_id',
        'sort_order',
        'name',
        'slug',
        'description',
        'image',
        'menu_heading',
        'promo_image',
        'promo_href',
        'promo_title',
        'promo_caption',
        'is_active',
    ];

    protected function casts(): array { return ['is_active' => 'boolean', 'sort_order' => 'integer']; }

    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name'); }

    public static function nextSortOrder(?int $parentId): int
    {
        $max = static::query()->where('parent_id', $parentId)->max('sort_order');
        return $max === null ? 0 : $max + 1;
    }
    public function products(): HasMany { return $this->hasMany(Product::class, 'category_id'); }
}
