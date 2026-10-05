<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('store_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained()->nullOnDelete();
            $table->string('store_key', 40);
            $table->timestamp('customer_read_at')->nullable();
            $table->timestamp('store_read_at')->nullable();
            $table->timestamps();
            $table->unique(['customer_id', 'store_key']);
            $table->index(['seller_id', 'updated_at']);
        });
        Schema::create('store_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_chat_id')->constrained()->cascadeOnDelete();
            $table->string('author_type', 20);
            $table->unsignedBigInteger('author_id');
            $table->text('body');
            $table->json('attachments')->nullable();
            $table->timestamps();
            $table->index(['store_chat_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_chat_messages');
        Schema::dropIfExists('store_chats');
    }
};
