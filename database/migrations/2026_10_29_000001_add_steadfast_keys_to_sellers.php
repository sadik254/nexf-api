<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A store's own Steadfast account. With it connected, packing the store's
 * parcel books it on that account; the keys are stored encrypted and never
 * returned. The webhook token lets the store's Steadfast account report back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            $table->text('steadfast_api_key')->nullable();
            $table->text('steadfast_secret_key')->nullable();
            $table->string('steadfast_webhook_token', 64)->nullable()->unique();
            $table->timestamp('steadfast_connected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            $table->dropUnique(['steadfast_webhook_token']);
            $table->dropColumn(['steadfast_api_key', 'steadfast_secret_key', 'steadfast_webhook_token', 'steadfast_connected_at']);
        });
    }
};
