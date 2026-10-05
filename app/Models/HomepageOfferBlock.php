<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomepageOfferBlock extends Model
{
    protected $fillable = ['row_type', 'slot', 'title', 'body', 'cta', 'href', 'image', 'mobile_image', 'theme', 'hidden'];
    protected function casts(): array { return ['slot' => 'integer', 'hidden' => 'boolean']; }
}
