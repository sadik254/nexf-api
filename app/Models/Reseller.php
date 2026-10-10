<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Reseller extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = ['name', 'email', 'phone', 'location', 'commission_rate', 'monthly_target', 'is_active', 'password'];
    protected $hidden = ['password', 'remember_token'];
    protected function casts(): array { return ['commission_rate' => 'decimal:2', 'is_active' => 'boolean', 'password' => 'hashed']; }
    public function customers(): HasMany { return $this->hasMany(Customer::class); }
    public function orders(): HasMany { return $this->hasMany(Order::class); }
}
