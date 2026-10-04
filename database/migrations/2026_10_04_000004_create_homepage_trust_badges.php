<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('homepage_trust_badges', function (Blueprint $table) {
            $table->id();
            $table->string('icon', 40);
            $table->string('label', 120);
            $table->string('tone', 30)->default('blue');
            $table->boolean('hidden')->default(false);
            $table->unsignedTinyInteger('sort_order');
            $table->timestamps();
        });
        $now = now();
        DB::table('homepage_trust_badges')->insert([
            ['icon' => 'BadgeCheck', 'label' => 'Authentic Product', 'tone' => 'pink', 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['icon' => 'Crown', 'label' => 'Premium Quality', 'tone' => 'violet', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['icon' => 'Truck', 'label' => 'Cash on Delivery', 'tone' => 'emerald', 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['icon' => 'RefreshCw', 'label' => 'Easy Return Policy', 'tone' => 'amber', 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['icon' => 'ShieldCheck', 'label' => 'Secured Payments', 'tone' => 'sky', 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void { Schema::dropIfExists('homepage_trust_badges'); }
};
