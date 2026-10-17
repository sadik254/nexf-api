<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('seller_promotions', function (Blueprint $table) { $table->string('layout', 8)->default('two')->after('title'); $table->json('blocks')->nullable()->after('layout'); $table->string('status', 16)->default('draft')->after('pinned'); $table->string('review_note', 500)->nullable()->after('status'); $table->timestamp('reviewed_at')->nullable()->after('review_note'); $table->index('status'); });
        foreach (DB::table('seller_promotions')->get() as $row) DB::table('seller_promotions')->where('id', $row->id)->update(['layout' => 'wide', 'blocks' => json_encode([['title' => $row->title, 'body' => $row->body, 'cta' => null, 'href' => null, 'image' => $row->image_url, 'mobile_image' => null, 'theme' => $row->theme ?: 'blue']]), 'status' => $row->expires_at && $row->expires_at < now()->toDateString() ? 'draft' : 'approved', 'reviewed_at' => now()]);
        Schema::table('seller_promotions', function (Blueprint $table) { $table->dropIndex(['pinned', 'expires_at']); $table->dropColumn(['body', 'code', 'discount_label', 'image_url', 'theme', 'expires_at']); $table->index('pinned'); });
    }
    public function down(): void { Schema::table('seller_promotions', function (Blueprint $table) { $table->text('body')->nullable(); $table->string('code', 80)->nullable(); $table->string('discount_label', 60)->default(''); $table->string('image_url', 1000)->nullable(); $table->string('theme', 24)->default('blue'); $table->date('expires_at')->nullable(); }); Schema::table('seller_promotions', function (Blueprint $table) { $table->dropIndex(['status']); $table->dropIndex(['pinned']); $table->dropColumn(['layout', 'blocks', 'status', 'review_note', 'reviewed_at']); $table->index(['pinned', 'expires_at']); }); }
};
