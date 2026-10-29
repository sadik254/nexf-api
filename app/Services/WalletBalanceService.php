<?php

namespace App\Services;

use App\Models\CustomerWalletTransaction;

class WalletBalanceService
{
    /** @return array{reward: float, withdrawable: float, total: float} */
    public function buckets(int $customerId, bool $lock = false): array
    {
        $query = CustomerWalletTransaction::where('customer_id', $customerId);
        if ($lock) $query->lockForUpdate();
        $row = $query->selectRaw("COALESCE(SUM(CASE WHEN bucket = 'withdrawable' THEN CASE WHEN type = 'credit' THEN amount ELSE -amount END ELSE 0 END), 0) withdrawable")
            ->selectRaw("COALESCE(SUM(CASE WHEN bucket <> 'withdrawable' OR bucket IS NULL THEN CASE WHEN type = 'credit' THEN amount ELSE -amount END ELSE 0 END), 0) reward")
            ->first();
        $reward = max(0, (float) $row->reward);
        $withdrawable = max(0, (float) $row->withdrawable);
        return ['reward' => $reward, 'withdrawable' => $withdrawable, 'total' => $reward + $withdrawable];
    }

    public function balance(int $customerId, bool $lock = false): float
    {
        return $this->buckets($customerId, $lock)['total'];
    }

    public function withdrawableBalance(int $customerId, bool $lock = false): float { return $this->buckets($customerId, $lock)['withdrawable']; }
}
