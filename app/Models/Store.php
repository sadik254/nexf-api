<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    public const PRIMARY_KEY = 'primary';

    protected $fillable = [
        'setup_key',
        'name',
        'logo',
    ];

    protected function casts(): array { return ['delivery_options_customized' => 'boolean']; }

    public static function primary(): ?self
    {
        return static::query()->where('setup_key', self::PRIMARY_KEY)->first();
    }
}
