<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('homepage_clients', function (Blueprint $table) {
            $table->string('logo_dark', 2048)->nullable()->after('logo');
        });
    }

    public function down(): void
    {
        Schema::table('homepage_clients', function (Blueprint $table) {
            $table->dropColumn('logo_dark');
        });
    }
};
