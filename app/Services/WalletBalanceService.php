<?php

namespace App\Services;

use App\Models\CustomerWalletTransaction;

class WalletBalanceService
{
    public function balance(int $customerId, bool $lock = false): float
    {
        $query = CustomerWalletTransaction::where('customer_id', $customerId);
        if ($lock) $query->lockForUpdate();
        return (float) $query->selectRaw("COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END), 0) total")->value('total');
    }
}
