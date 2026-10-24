<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerPayoutAccount extends Model
{
    protected $fillable = ['seller_id', 'reseller_id', 'provider', 'account', 'holder', 'bank', 'branch', 'is_selected'];

    protected function casts(): array
    {
        return ['is_selected' => 'boolean'];
    }
}
