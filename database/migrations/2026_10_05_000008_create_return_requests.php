<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('return_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('support_ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->string('status', 30)->default('requested');
            $table->unsignedInteger('quantity');
            $table->text('reason');
            $table->text('decision_note')->nullable();
            $table->string('return_tracking')->nullable();
            $table->string('outcome_reference')->nullable();
            $table->decimal('refund_amount', 12, 2)->unsigned()->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'created_at']);
            $table->index(['seller_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('return_requests'); }
};
