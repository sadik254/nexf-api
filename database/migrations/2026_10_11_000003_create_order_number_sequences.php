<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_number_sequences', function (Blueprint $table): void {
            $table->string('prefix', 8)->primary();
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
        });
        \Illuminate\Support\Facades\DB::table('order_number_sequences')->insert(['prefix' => 'NEXF', 'next_value' => 1, 'created_at' => now(), 'updated_at' => now()]);
        \Illuminate\Support\Facades\DB::table('order_number_sequences')->insert(['prefix' => 'EXC', 'next_value' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_number_sequences');
    }
};
