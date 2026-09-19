<?php

use Database\Seeders\ProductCategorySeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        app(ProductCategorySeeder::class)->run();
    }

    // Existing catalogue data is never removed during rollback.
    public function down(): void {}
};
