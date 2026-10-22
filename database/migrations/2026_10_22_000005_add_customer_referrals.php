<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void { Schema::table('customers',function(Blueprint $t){$t->string('referral_code',24)->nullable()->unique()->after('profile_picture');$t->foreignId('referred_by_customer_id')->nullable()->after('referral_code')->constrained('customers')->nullOnDelete();});Schema::table('pending_customer_registrations',function(Blueprint $t){$t->string('referral_code',24)->nullable()->after('profile_picture');}); }
 public function down():void {Schema::table('pending_customer_registrations',fn(Blueprint $t)=>$t->dropColumn('referral_code'));Schema::table('customers',function(Blueprint $t){$t->dropConstrainedForeignId('referred_by_customer_id');$t->dropUnique(['referral_code']);$t->dropColumn('referral_code');});}
};
