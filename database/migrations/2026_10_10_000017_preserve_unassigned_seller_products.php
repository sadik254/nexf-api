<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::table('sellers',fn(Blueprint $table)=>$table->timestamp('roster_archived_at')->nullable());Schema::table('products',fn(Blueprint $table)=>$table->boolean('owner_unassigned')->default(false));}
 public function down():void {Schema::table('sellers',fn(Blueprint $table)=>$table->dropColumn('roster_archived_at'));Schema::table('products',fn(Blueprint $table)=>$table->dropColumn('owner_unassigned'));}
};
