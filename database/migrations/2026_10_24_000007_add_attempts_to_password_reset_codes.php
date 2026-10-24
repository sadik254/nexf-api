<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void { Schema::table('password_reset_codes', fn (Blueprint $table) => $table->unsignedTinyInteger('failed_attempts')->default(0)->after('code_hash')); }
    public function down(): void { Schema::table('password_reset_codes', fn (Blueprint $table) => $table->dropColumn('failed_attempts')); }
};
