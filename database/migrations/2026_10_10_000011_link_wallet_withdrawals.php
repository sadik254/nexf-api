<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {public function up():void {
 Schema::table('customer_wallet_transactions',fn(Blueprint $t)=>$t->foreignId('withdrawal_request_id')->nullable()->constrained('withdrawal_requests')->nullOnDelete());
 DB::table('withdrawal_requests')->orderBy('id')->chunkById(200,function($requests){foreach($requests as $r){DB::table('customer_wallet_transactions')->where('customer_id',$r->customer_id)->whereIn('description',["Withdrawal request #{$r->id}","Rejected withdrawal #{$r->id}"])->update(['withdrawal_request_id'=>$r->id]);}});
 }public function down():void{Schema::table('customer_wallet_transactions',fn(Blueprint $t)=>$t->dropConstrainedForeignId('withdrawal_request_id'));}};
