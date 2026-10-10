<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::table('sellers',function(Blueprint $table){
  $table->string('seller_name')->nullable()->change();
  $table->enum('kyc_type',['nid','passport'])->nullable()->change();
  $table->string('kyc_number')->nullable()->change();$table->string('kyc_document_url')->nullable()->change();$table->string('product_category')->nullable()->change();
  $table->string('sku_prefix',4)->nullable()->unique();$table->decimal('commission_rate',5,2)->nullable();
  $table->string('address_line')->nullable();$table->string('address_area')->nullable();$table->string('address_district')->nullable();
 });}
 public function down():void {Schema::table('sellers',function(Blueprint $table){$table->dropUnique(['sku_prefix']);$table->dropColumn(['sku_prefix','commission_rate','address_line','address_area','address_district']);});}
};
