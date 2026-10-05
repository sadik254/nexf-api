<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomepageCategoryCard extends Model
{
    protected $fillable = ['category_id', 'name', 'caption', 'href', 'image', 'mobile_image', 'cta', 'theme', 'hidden', 'sort_order'];

    protected function casts(): array { return ['hidden' => 'boolean', 'sort_order' => 'integer']; }

    public function category(): BelongsTo { return $this->belongsTo(ProductCategory::class, 'category_id'); }
}
