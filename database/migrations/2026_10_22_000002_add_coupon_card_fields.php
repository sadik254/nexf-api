<?php
use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up():void{Schema::table('homepage_offer_blocks',function(Blueprint $t){$t->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();$t->date('expires_at')->nullable();$t->string('store_name',120)->nullable();});} public function down():void{Schema::table('homepage_offer_blocks',function(Blueprint $t){$t->dropConstrainedForeignId('coupon_id');$t->dropColumn(['expires_at','store_name']);});}};
