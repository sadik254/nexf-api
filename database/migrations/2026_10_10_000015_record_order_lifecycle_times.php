<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::table('orders',fn(Blueprint $table)=>$table->timestamp('payment_paid_at')->nullable());Schema::table('order_items',fn(Blueprint $table)=>$table->timestamp('confirmed_at')->nullable());}
 public function down():void {Schema::table('orders',fn(Blueprint $table)=>$table->dropColumn('payment_paid_at'));Schema::table('order_items',fn(Blueprint $table)=>$table->dropColumn('confirmed_at'));}
};
