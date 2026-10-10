<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void { Schema::table('shipping_methods',function(Blueprint $t){$t->foreignId('seller_id')->nullable()->constrained('sellers')->cascadeOnDelete();});Schema::table('sellers',function(Blueprint $t){$t->boolean('delivery_options_customized')->default(false);}); }
 public function down():void {Schema::table('shipping_methods',fn(Blueprint $t)=>$t->dropConstrainedForeignId('seller_id'));Schema::table('sellers',fn(Blueprint $t)=>$t->dropColumn('delivery_options_customized'));}
};
