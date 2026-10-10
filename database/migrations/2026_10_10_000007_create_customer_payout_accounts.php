<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {Schema::create('customer_payout_accounts',function(Blueprint $t){$t->id();$t->foreignId('customer_id')->constrained()->cascadeOnDelete();$t->string('provider',40);$t->string('account',100);$t->string('holder',150)->nullable();$t->string('bank',150)->nullable();$t->string('branch',150)->nullable();$t->boolean('is_selected')->default(false);$t->timestamps();});}
 public function down(): void {Schema::dropIfExists('customer_payout_accounts');}
};
