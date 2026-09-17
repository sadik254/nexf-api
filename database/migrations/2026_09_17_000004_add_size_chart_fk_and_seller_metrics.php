<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::table('products', function (Blueprint $t) { $t->foreign('size_chart_id')->references('id')->on('size_charts')->nullOnDelete(); }); Schema::table('sellers', function (Blueprint $t) { $t->unsignedTinyInteger('positive_rating_percentage')->nullable(); $t->unsignedTinyInteger('on_time_shipping_percentage')->nullable(); $t->unsignedTinyInteger('chat_response_percentage')->nullable(); }); } public function down(): void { Schema::table('products', function (Blueprint $t) { $t->dropForeign(['size_chart_id']); }); Schema::table('sellers', function (Blueprint $t) { $t->dropColumn(['positive_rating_percentage','on_time_shipping_percentage','chat_response_percentage']); }); } };
