<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fraud_guard_settings', function (Blueprint $table): void {
            $table->foreignId('seller_id')->nullable()->after('id')->constrained('sellers')->nullOnDelete();
            $table->unique('seller_id');
        });

        Schema::table('fraud_guard_blocks', function (Blueprint $table): void {
            $table->foreignId('seller_id')->nullable()->after('id')->constrained('sellers')->nullOnDelete();
            $table->string('created_by_type', 32)->nullable()->after('created_by_admin_id');
            $table->unsignedBigInteger('created_by_id')->nullable()->after('created_by_type');
            $table->dropUnique('fraud_guard_blocks_kind_value_unique');
            $table->unique(['seller_id', 'kind', 'value']);
        });
    }

    public function down(): void
    {
        Schema::table('fraud_guard_blocks', function (Blueprint $table): void {
            $table->dropUnique('fraud_guard_blocks_seller_id_kind_value_unique');
            $table->unique(['kind', 'value']);
            $table->dropConstrainedForeignId('seller_id');
            $table->dropColumn(['created_by_type', 'created_by_id']);
        });

        Schema::table('fraud_guard_settings', function (Blueprint $table): void {
            $table->dropUnique('fraud_guard_settings_seller_id_unique');
            $table->dropConstrainedForeignId('seller_id');
        });
    }
};
