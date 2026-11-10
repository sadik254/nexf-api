<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->string('reply_token', 64)->nullable()->unique()->after('reference');
        });
        Schema::table('support_messages', function (Blueprint $table): void {
            $table->string('inbound_message_id', 255)->nullable()->unique()->after('attachments');
        });
    }

    public function down(): void
    {
        Schema::table('support_messages', function (Blueprint $table): void {
            $table->dropUnique(['inbound_message_id']);
            $table->dropColumn('inbound_message_id');
        });
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropUnique(['reply_token']);
            $table->dropColumn('reply_token');
        });
    }
};
