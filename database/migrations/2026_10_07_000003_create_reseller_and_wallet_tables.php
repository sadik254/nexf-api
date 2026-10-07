<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('resellers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone', 32)->nullable();
            $table->string('location')->nullable();
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->unsignedInteger('monthly_target')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('reseller_id')->nullable()->after('id')->constrained('resellers')->nullOnDelete();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('reseller_id')->nullable()->after('customer_id')->constrained('resellers')->nullOnDelete();
        });

        Schema::create('customer_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('return_request_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['credit', 'debit']);
            $table->decimal('amount', 12, 2);
            $table->string('description');
            $table->timestamps();
            $table->unique('return_request_id');
        });
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 40);
            $table->string('account_details', 500);
            $table->enum('status', ['requested', 'paid', 'rejected'])->default('requested');
            $table->text('admin_note')->nullable();
            $table->foreignId('processed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
        Schema::dropIfExists('customer_wallet_transactions');
        Schema::table('orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('reseller_id'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropConstrainedForeignId('reseller_id'));
        Schema::dropIfExists('resellers');
    }
};
