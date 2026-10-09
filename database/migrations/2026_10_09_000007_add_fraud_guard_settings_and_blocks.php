<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('fraud_guard_settings', function (Blueprint $table) { $table->id(); $table->boolean('enabled')->default(true); $table->boolean('ip_block')->default(true); $table->boolean('device_block')->default(true); $table->boolean('phone_blacklist')->default(true); $table->boolean('fake_number_detection')->default(true); $table->timestamps(); });
        Schema::create('fraud_guard_blocks', function (Blueprint $table) { $table->id(); $table->string('kind', 12); $table->string('value', 128); $table->string('reason', 200)->nullable(); $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete(); $table->timestamps(); $table->unique(['kind', 'value']); });
    }
    public function down(): void { Schema::dropIfExists('fraud_guard_blocks'); Schema::dropIfExists('fraud_guard_settings'); }
};
