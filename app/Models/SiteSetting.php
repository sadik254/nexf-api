<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    public $incrementing = false;
    protected $primaryKey = 'key';
    protected $keyType = 'string';
    protected $fillable = ['key', 'payload'];
    protected function casts(): array { return ['payload' => 'array']; }
}
