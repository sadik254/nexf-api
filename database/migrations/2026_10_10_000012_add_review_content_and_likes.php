<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {public function up():void {
Schema::table('reviews',function(Blueprint $t){$t->json('images')->nullable();$t->json('videos')->nullable();$t->text('rejection_note')->nullable();$t->timestamp('moderated_at')->nullable();});
Schema::create('review_likes',function(Blueprint $t){$t->id();$t->foreignId('review_id')->constrained()->cascadeOnDelete();$t->foreignId('customer_id')->constrained()->cascadeOnDelete();$t->string('target_type',20)->default('review');$t->timestamps();$t->unique(['review_id','customer_id','target_type']);});
}public function down():void {Schema::dropIfExists('review_likes');Schema::table('reviews',fn(Blueprint $t)=>$t->dropColumn(['images','videos','rejection_note','moderated_at']));}};
