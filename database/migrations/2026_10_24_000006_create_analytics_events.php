<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 100);
            $table->string('visitor_id', 100)->nullable();
            $table->foreignId('seller_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 40);
            $table->string('page', 500)->nullable();
            $table->string('channel', 80)->nullable();
            $table->string('referrer', 500)->nullable();
            $table->string('device', 40)->nullable();
            $table->string('location', 120)->nullable();
            $table->ipAddress('ip')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['created_at', 'event']);
            $table->index(['seller_id', 'created_at']);
            $table->index(['session_id', 'created_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('analytics_events'); }
};
