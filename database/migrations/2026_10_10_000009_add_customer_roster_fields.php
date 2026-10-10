<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::table('customers',function(Blueprint $t){$t->string('email')->nullable()->change();$t->string('crm_status',20)->default('new');$t->string('contact_street',500)->nullable();$t->string('contact_area',150)->nullable();$t->string('contact_district',100)->nullable();$t->timestamp('roster_archived_at')->nullable();});}
 public function down():void {Schema::table('customers',fn(Blueprint $t)=>$t->dropColumn(['crm_status','contact_street','contact_area','contact_district','roster_archived_at']));}
};
