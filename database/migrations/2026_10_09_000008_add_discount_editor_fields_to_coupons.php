<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void { Schema::table('coupons', function (Blueprint $table) { $table->string('discount_kind', 16)->default('order')->after('is_automatic'); $table->boolean('combines')->default(false)->after('discount_kind'); $table->json('eligible_collection_ids')->nullable()->after('eligible_category_ids'); $table->json('buy_product_ids')->nullable()->after('eligible_collection_ids'); $table->json('buy_category_ids')->nullable()->after('buy_product_ids'); $table->json('buy_collection_ids')->nullable()->after('buy_category_ids'); $table->unsignedInteger('buy_quantity')->nullable()->after('minimum_quantity'); $table->unsignedInteger('get_quantity')->nullable()->after('buy_quantity'); $table->string('reward_type', 16)->nullable()->after('get_quantity'); }); }
    public function down(): void { Schema::table('coupons', function (Blueprint $table) { $table->dropColumn(['discount_kind','combines','eligible_collection_ids','buy_product_ids','buy_category_ids','buy_collection_ids','buy_quantity','get_quantity','reward_type']); }); }
};
