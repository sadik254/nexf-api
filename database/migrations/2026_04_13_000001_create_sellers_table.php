<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('seller_name');
            $table->string('email')->unique();
            $table->string('phone', 32)->nullable()->unique();

            $table->string('store_name');
            $table->string('store_slug')->unique();
            $table->text('store_address')->nullable();
            $table->string('store_logo')->nullable();
            $table->string('store_image')->nullable();

            $table->string('seller_image')->nullable();

            $table->enum('kyc_type', ['nid', 'passport']);
            $table->string('kyc_number');
            $table->string('kyc_document_url');

            $table->string('product_category');

            $table->string('support_email')->nullable();
            $table->string('support_phone', 32)->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected', 'suspended'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->string('password')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('login_ip', 45)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->boolean('is_active')->default(false);

            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sellers');
    }
};
