<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomepageClient extends Model
{
    protected $fillable = ['name', 'logo', 'logo_dark', 'url', 'industry', 'hidden', 'sort_order'];

    protected $appends = ['original_logo', 'original_logo_dark'];

    protected function casts(): array
    {
        return ['hidden' => 'boolean', 'sort_order' => 'integer'];
    }

    public function getOriginalLogoAttribute(): ?string
    {
        return $this->attributes['logo'] ?? null;
    }

    public function getOriginalLogoDarkAttribute(): ?string
    {
        return $this->attributes['logo_dark'] ?? null;
    }
}
