<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('partner_payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('reseller_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('account', 100);
            $table->string('holder', 150)->nullable();
            $table->string('bank', 150)->nullable();
            $table->string('branch', 150)->nullable();
            $table->boolean('is_selected')->default(false);
            $table->timestamps();
            $table->index(['seller_id', 'is_selected']);
            $table->index(['reseller_id', 'is_selected']);
        });

        Schema::create('partner_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reseller_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('partner_payout_account_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('requested');
            $table->string('reference', 32)->nullable()->unique();
            $table->string('transaction_id', 255)->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('processed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['seller_id', 'status']);
            $table->index(['reseller_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_withdrawals');
        Schema::dropIfExists('partner_payout_accounts');
    }
};
