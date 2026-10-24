<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerWithdrawal extends Model
{
    protected $fillable = ['seller_id', 'reseller_id', 'partner_payout_account_id', 'amount', 'status', 'reference', 'transaction_id', 'admin_note', 'processed_by_admin_id', 'processed_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'processed_at' => 'datetime'];
    }

    public function seller() { return $this->belongsTo(Seller::class); }
    public function reseller() { return $this->belongsTo(Reseller::class); }
    public function account() { return $this->belongsTo(PartnerPayoutAccount::class, 'partner_payout_account_id'); }
}
