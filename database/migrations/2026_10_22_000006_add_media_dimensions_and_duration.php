<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration{public function up():void{Schema::table('media_assets',function(Blueprint $t){$t->unsignedInteger('width')->nullable()->after('size_bytes');$t->unsignedInteger('height')->nullable()->after('width');$t->unsignedInteger('duration_seconds')->nullable()->after('height');});}public function down():void{Schema::table('media_assets',fn(Blueprint $t)=>$t->dropColumn(['width','height','duration_seconds']));}};
