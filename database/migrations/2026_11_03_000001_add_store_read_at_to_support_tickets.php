<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->timestamp('store_read_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', fn (Blueprint $table) => $table->dropColumn('store_read_at'));
    }
};
