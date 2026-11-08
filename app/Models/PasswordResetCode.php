<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetCode extends Model
{
    protected $fillable = ['account_type', 'account_id', 'email', 'code_hash', 'failed_attempts', 'is_invite', 'expires_at', 'used_at', 'replaced_at', 'fulfilled_at'];

    protected function casts(): array
    {
        return ['is_invite' => 'boolean', 'expires_at' => 'datetime', 'used_at' => 'datetime', 'replaced_at' => 'datetime', 'fulfilled_at' => 'datetime'];
    }
}
