<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reseller extends Model
{
    protected $fillable = ['name', 'email', 'phone', 'location', 'commission_rate', 'monthly_target', 'is_active'];
    protected function casts(): array { return ['commission_rate' => 'decimal:2', 'is_active' => 'boolean']; }
    public function customers(): HasMany { return $this->hasMany(Customer::class); }
    public function orders(): HasMany { return $this->hasMany(Order::class); }
}
