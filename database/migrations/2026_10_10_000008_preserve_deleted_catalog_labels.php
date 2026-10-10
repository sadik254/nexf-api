<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{foreach(['brands','tags'] as $table)Schema::table($table,fn(Blueprint $t)=>$t->softDeletes());}
 public function down():void{foreach(['brands','tags'] as $table)Schema::table($table,fn(Blueprint $t)=>$t->dropSoftDeletes());}
};
