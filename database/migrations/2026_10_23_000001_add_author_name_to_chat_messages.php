<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('store_chat_messages', function (Blueprint $table) {
            $table->string('author_name')->nullable()->after('author_id');
        });
        Schema::table('support_messages', function (Blueprint $table) {
            $table->string('author_name')->nullable()->after('author_id');
        });
    }

    public function down(): void
    {
        Schema::table('store_chat_messages', fn (Blueprint $table) => $table->dropColumn('author_name'));
        Schema::table('support_messages', fn (Blueprint $table) => $table->dropColumn('author_name'));
    }
};
