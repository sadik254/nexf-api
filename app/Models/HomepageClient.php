<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomepageClient extends Model
{
    protected $fillable = ['name', 'logo', 'url', 'industry', 'hidden', 'sort_order'];

    protected $appends = ['original_logo'];

    protected function casts(): array
    {
        return ['hidden' => 'boolean', 'sort_order' => 'integer'];
    }

    public function getOriginalLogoAttribute(): ?string
    {
        return $this->attributes['logo'] ?? null;
    }
}
