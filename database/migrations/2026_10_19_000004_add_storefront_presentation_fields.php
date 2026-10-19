<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('customer_followed_house_stores', function(Blueprint $t){$t->id();$t->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();$t->timestamps();});
  Schema::table('product_categories', function(Blueprint $t){$t->string('promo_title',120)->nullable();$t->string('promo_caption',255)->nullable();});
  Schema::table('homepage_banners', function(Blueprint $t){$t->string('eyebrow',100)->nullable();$t->string('text_alignment',16)->nullable();});
 }
 public function down(): void {Schema::dropIfExists('customer_followed_house_stores');Schema::table('product_categories',fn(Blueprint $t)=>$t->dropColumn(['promo_title','promo_caption']));Schema::table('homepage_banners',fn(Blueprint $t)=>$t->dropColumn(['eyebrow','text_alignment']));}
};
