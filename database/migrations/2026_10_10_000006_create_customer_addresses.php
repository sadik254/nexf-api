<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('customer_addresses', function(Blueprint $table) {
  $table->id(); $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
  $table->string('label',100); $table->string('recipient',150); $table->string('phone',20);
  $table->text('line'); $table->string('area',150); $table->string('district',100);
  $table->boolean('is_default')->default(false); $table->timestamps();
 }); }
 public function down(): void { Schema::dropIfExists('customer_addresses'); }
};
