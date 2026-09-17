<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::table('products', function (Blueprint $t) { $t->decimal('compare_at_price', 12, 2)->nullable()->after('default_selling_price'); $t->json('option_groups')->nullable()->after('gallery'); $t->unsignedBigInteger('size_chart_id')->nullable()->after('option_groups'); }); } public function down(): void { Schema::table('products', function (Blueprint $t) { $t->dropColumn(['compare_at_price','option_groups','size_chart_id']); }); } };
