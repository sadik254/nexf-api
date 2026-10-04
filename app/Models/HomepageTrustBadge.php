<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomepageTrustBadge extends Model
{
    protected $fillable = ['icon', 'label', 'tone', 'hidden', 'sort_order'];
    protected function casts(): array { return ['hidden' => 'boolean', 'sort_order' => 'integer']; }
}
