<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::table('shipping_methods',fn(Blueprint $t)=>$t->boolean('is_store_option')->default(false));Schema::table('stores',fn(Blueprint $t)=>$t->boolean('delivery_options_customized')->default(false));}
 public function down():void {Schema::table('shipping_methods',fn(Blueprint $t)=>$t->dropColumn('is_store_option'));Schema::table('stores',fn(Blueprint $t)=>$t->dropColumn('delivery_options_customized'));}
};
