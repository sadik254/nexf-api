<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {public function up():void {Schema::table('product_questions',function(Blueprint $t){$t->string('status',20)->default('approved');$t->text('rejection_note')->nullable();$t->timestamp('moderated_at')->nullable();});}public function down():void {Schema::table('product_questions',fn(Blueprint $t)=>$t->dropColumn(['status','rejection_note','moderated_at']));}};
