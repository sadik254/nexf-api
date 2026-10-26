<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->string('contact_ip', 45)->nullable()->after('guest_email');
            $table->index(['contact_ip', 'created_at']);
            $table->index(['guest_email', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropIndex(['contact_ip', 'created_at']);
            $table->dropIndex(['guest_email', 'created_at']);
            $table->dropColumn('contact_ip');
        });
    }
};
