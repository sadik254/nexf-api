<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('homepage_banners', function (Blueprint $table) { $table->string('cta', 100)->nullable(); $table->string('theme', 20)->nullable(); });
  Schema::table('support_tickets', function (Blueprint $table) { $table->string('guest_name', 150)->nullable(); $table->string('guest_email', 255)->nullable(); });
 }
 public function down(): void {
  Schema::table('homepage_banners', fn (Blueprint $table) => $table->dropColumn(['cta', 'theme']));
  Schema::table('support_tickets', fn (Blueprint $table) => $table->dropColumn(['guest_name', 'guest_email']));
 }
};
