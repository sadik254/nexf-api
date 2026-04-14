<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->nullable()->constrained('sellers')->nullOnDelete();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('category_id')->constrained('product_categories')->cascadeOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->enum('product_type', ['simple', 'variable'])->default('simple');
            $table->enum('status', ['draft', 'active', 'inactive'])->default('draft');

            $table->string('thumbnail')->nullable();
            $table->json('gallery')->nullable();

            $table->decimal('default_buying_price', 12, 2)->nullable();
            $table->decimal('default_selling_price', 12, 2)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
