<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomepageBanner extends Model
{

    protected $appends = ['original_image'];

    public function getOriginalImageAttribute(): ?string
    {
        return $this->attributes['image'] ?? null;
    }
    protected $fillable = ['placement', 'image', 'href', 'title', 'emphasis', 'subtitle', 'eyebrow', 'text_alignment', 'cta', 'theme', 'is_active', 'sort_order'];
    protected function casts(): array { return ['is_active' => 'boolean', 'sort_order' => 'integer']; }
}
