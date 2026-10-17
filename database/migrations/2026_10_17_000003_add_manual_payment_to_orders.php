<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_reference', 60)->nullable()->after('paid_amount');
            $table->string('payment_sender', 40)->nullable()->after('payment_reference');
            $table->timestamp('payment_submitted_at')->nullable()->after('payment_sender');
        });

        $now = now();
        foreach ([
            ['bkash', 'bKash', 'Send money to our bKash number.', 5],
            ['nagad', 'Nagad', 'Send money to our Nagad number.', 6],
            ['rocket', 'Rocket', 'Send money to our Rocket number.', 7],
            ['bank', 'Bank Transfer', 'Transfer to our bank account.', 8],
        ] as [$code, $name, $description, $sortOrder]) {
            if (!DB::table('payment_methods')->where('code', $code)->exists()) {
                DB::table('payment_methods')->insert([
                    'name' => $name,
                    'code' => $code,
                    'description' => $description,
                    'is_active' => true,
                    'sort_order' => $sortOrder,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_reference', 'payment_sender', 'payment_submitted_at']);
        });
    }
};
