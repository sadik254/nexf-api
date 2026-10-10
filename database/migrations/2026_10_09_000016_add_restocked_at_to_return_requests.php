<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->timestamp('restocked_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->dropColumn('restocked_at');
        });
    }
};
