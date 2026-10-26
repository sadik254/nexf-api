<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_events', function (Blueprint $table): void {
            $table->string('store_type', 20)->nullable()->after('seller_id');
            $table->index(['store_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('analytics_events', function (Blueprint $table): void {
            $table->dropIndex(['store_type', 'created_at']);
            $table->dropColumn('store_type');
        });
    }
};
