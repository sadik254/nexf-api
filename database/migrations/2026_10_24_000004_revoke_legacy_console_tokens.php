<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Support\Facades\DB;
return new class extends Migration { public function up():void { DB::table('personal_access_tokens')->whereIn('tokenable_type',['App\\Models\\Admin','App\\Models\\Seller','App\\Models\\SellerStaff','App\\Models\\Reseller'])->delete(); } public function down():void{} };
