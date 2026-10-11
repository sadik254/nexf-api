<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('payment_methods')->insertOrIgnore([
            'code' => 'wallet', 'name' => 'NEXF Balance', 'description' => 'Pay using your available NEXF balance.',
            'is_active' => true, 'sort_order' => 4, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (!DB::table('orders')->where('payment_method_code', 'wallet')->exists()) {
            DB::table('payment_methods')->where('code', 'wallet')->delete();
        }
    }
};
