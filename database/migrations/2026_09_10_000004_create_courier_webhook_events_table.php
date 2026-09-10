<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('courier_webhook_events', function (Blueprint $table) {
            $table->id(); $table->string('provider'); $table->string('notification_type');
            $table->string('consignment_id')->nullable(); $table->string('invoice')->nullable();
            $table->string('event_hash')->unique(); $table->json('payload');
            $table->timestamp('processed_at')->nullable(); $table->text('processing_error')->nullable(); $table->timestamps();
            $table->index(['provider', 'consignment_id']); $table->index(['provider', 'invoice']);
        });
    }
    public function down(): void { Schema::dropIfExists('courier_webhook_events'); }
};
