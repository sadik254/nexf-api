<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!DB::table('payment_methods')->where('code', 'upay')->exists()) {
            DB::table('payment_methods')->insert([
                'code' => 'upay', 'name' => 'Upay',
                'description' => 'Send money to our Upay number.',
                'is_active' => true, 'sort_order' => 8,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('payment_methods')->where('code', 'bank')->update(['sort_order' => 9]);
    }

    public function down(): void
    {
        DB::table('payment_methods')->where('code', 'upay')->delete();
    }
};
