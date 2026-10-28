<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('console_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('recipient_type', 20); // admin, seller, reseller
            $table->unsignedBigInteger('recipient_id');
            $table->string('type', 60);
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->string('source_key', 160)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['recipient_type', 'recipient_id', 'read_at', 'created_at'], 'console_notify_recipient_idx');
            $table->unique(['recipient_type', 'recipient_id', 'source_key'], 'console_notify_source_unique');
        });
    }

    public function down(): void { Schema::dropIfExists('console_notifications'); }
};
