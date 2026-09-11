<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('password_reset_codes', function (Blueprint $table) {
            $table->id();
            $table->string('account_type', 32);
            $table->unsignedBigInteger('account_id');
            $table->string('email')->index();
            $table->string('code_hash');
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable()->index();
            $table->timestamps();
            $table->index(['account_type', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_codes');
    }
};
