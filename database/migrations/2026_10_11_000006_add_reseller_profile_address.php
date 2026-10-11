<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('resellers', function (Blueprint $table): void {
            $table->string('address_line')->nullable();
            $table->string('address_area')->nullable();
            $table->string('address_district')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table): void {
            $table->dropColumn(['address_line', 'address_area', 'address_district']);
        });
    }
};
