<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            // Keep the tier that set this combo's price even if the seller
            // later edits or removes the combo definition.
            $table->unsignedBigInteger('bundle_source_id')->nullable()->after('bundle_label');
            $table->string('bundle_source_label', 60)->nullable()->after('bundle_source_id');
            $table->unsignedInteger('bundle_source_size')->nullable()->after('bundle_source_label');
            $table->decimal('bundle_source_price', 12, 2)->nullable()->after('bundle_source_size');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['bundle_source_id', 'bundle_source_label', 'bundle_source_size', 'bundle_source_price']);
        });
    }
};
