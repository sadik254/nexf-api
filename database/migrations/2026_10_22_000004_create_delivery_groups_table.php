<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('delivery_groups', function(Blueprint $table){ $table->id();$table->string('name');$table->json('seller_ids');$table->boolean('includes_house')->default(false);$table->foreignId('ships_from_seller_id')->nullable()->constrained('sellers')->nullOnDelete();$table->timestamps(); }); }
 public function down(): void { Schema::dropIfExists('delivery_groups'); }
};
