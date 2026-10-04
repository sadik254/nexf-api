<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomepageNotice extends Model
{
    protected $fillable = ['text', 'enabled', 'sort_order'];
    protected function casts(): array { return ['enabled' => 'boolean', 'sort_order' => 'integer']; }
}
