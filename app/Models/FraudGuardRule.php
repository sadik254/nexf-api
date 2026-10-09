<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FraudGuardRule extends Model
{
    protected $fillable = ['name', 'rule_type', 'configuration', 'is_active', 'note'];
    protected function casts(): array { return ['configuration' => 'array', 'is_active' => 'boolean']; }
}
