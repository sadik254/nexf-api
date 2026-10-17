<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Old abandoned registrations were customer rows. Preserve any row with
        // an order, but remove unverified rows that have never transacted.
        DB::table('customers')
            ->whereNull('email_verified_at')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('orders')
                    ->whereColumn('orders.customer_id', 'customers.id');
            })
            ->delete();
    }

    public function down(): void
    {
        // Deleted abandoned registrations cannot be recovered.
    }
};
