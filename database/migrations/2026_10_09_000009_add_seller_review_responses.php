<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void { Schema::table('reviews', function (Blueprint $table) { $table->text('seller_response')->nullable()->after('comment'); $table->timestamp('seller_responded_at')->nullable()->after('seller_response'); }); }
    public function down(): void { Schema::table('reviews', function (Blueprint $table) { $table->dropColumn(['seller_response','seller_responded_at']); }); }
};
