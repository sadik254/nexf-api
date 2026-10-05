<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 20);
            $table->unsignedBigInteger('owner_id');
            $table->string('source', 20);
            $table->text('url');
            $table->string('file_name', 255);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('alt_text', 255)->nullable();
            $table->timestamps();
            $table->index(['owner_type', 'owner_id', 'created_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('media_assets'); }
};
