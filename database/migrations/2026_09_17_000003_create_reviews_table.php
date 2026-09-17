<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::create('reviews', function (Blueprint $t) { $t->id(); $t->foreignId('product_id')->constrained()->cascadeOnDelete(); $t->foreignId('customer_id')->constrained()->cascadeOnDelete(); $t->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete(); $t->unsignedTinyInteger('rating'); $t->text('comment')->nullable(); $t->enum('status', ['pending','approved','rejected'])->default('pending'); $t->timestamps(); $t->index(['product_id','status']); }); } public function down(): void { Schema::dropIfExists('reviews'); } };
