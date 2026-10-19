<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomepageBanner extends Model
{

    protected $appends = ['original_image'];

    public function getOriginalImageAttribute(): ?string
    {
        $image = $this->attributes['image'] ?? null;
        if (!$image || !preg_match('~^https://ucarecdn\.com/([a-f0-9-]{36})(?:/.*)?$~i', $image, $matches)) return $image;
        return 'https://ucarecdn.com/'.$matches[1].'/';
    }
    protected $fillable = ['placement', 'image', 'href', 'title', 'emphasis', 'subtitle', 'eyebrow', 'text_alignment', 'cta', 'theme', 'is_active', 'sort_order'];
    protected function casts(): array { return ['is_active' => 'boolean', 'sort_order' => 'integer']; }
}
