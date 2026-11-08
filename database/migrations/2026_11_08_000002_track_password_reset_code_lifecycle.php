<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('password_reset_codes', function (Blueprint $table): void {
            $table->boolean('is_invite')->default(false)->after('failed_attempts');
            $table->timestamp('replaced_at')->nullable()->after('used_at')->index();
            $table->timestamp('fulfilled_at')->nullable()->after('replaced_at');
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_codes', function (Blueprint $table): void {
            $table->dropIndex(['replaced_at']);
            $table->dropColumn(['is_invite', 'replaced_at', 'fulfilled_at']);
        });
    }
};
