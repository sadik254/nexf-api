<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('content_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('target_type', 40);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 100);
            $table->text('note')->nullable();
            $table->enum('status', ['open', 'actioned', 'dismissed'])->default('open');
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'target_type', 'target_id']);
        });

        Schema::create('fraud_guard_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('rule_type', 60);
            $table->json('configuration');
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->boolean('is_automatic')->default(false)->after('code');
            $table->string('applies_to', 30)->default('order')->after('discount_type');
            $table->unsignedInteger('minimum_quantity')->nullable()->after('minimum_order_amount');
            $table->json('eligible_product_ids')->nullable()->after('minimum_quantity');
            $table->json('eligible_category_ids')->nullable()->after('eligible_product_ids');
            $table->foreignId('seller_id')->nullable()->after('created_by_admin_id')->constrained('sellers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seller_id');
            $table->dropColumn(['is_automatic', 'applies_to', 'minimum_quantity', 'eligible_product_ids', 'eligible_category_ids']);
        });
        Schema::dropIfExists('fraud_guard_rules');
        Schema::dropIfExists('content_reports');
    }
};
