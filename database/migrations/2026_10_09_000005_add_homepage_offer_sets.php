<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('homepage_offer_sets', function (Blueprint $table) { $table->id(); $table->string('row_type', 20); $table->string('name', 120); $table->timestamps(); });
        Schema::table('homepage_offer_blocks', function (Blueprint $table) { $table->foreignId('offer_set_id')->nullable()->after('id')->constrained('homepage_offer_sets')->cascadeOnDelete(); });
        Schema::table('homepage_offer_blocks', function (Blueprint $table) { $table->dropUnique('homepage_offer_blocks_row_type_slot_unique'); $table->unique(['offer_set_id','slot']); });
        // Preserve every deployed row as the initial selectable set.  This is a
        // data migration, so existing storefront content keeps rendering after
        // the new relation is introduced.
        foreach (['four', 'wide', 'two'] as $rowType) {
            $blocks = DB::table('homepage_offer_blocks')->where('row_type', $rowType)->whereNull('offer_set_id')->get();
            if ($blocks->isEmpty()) continue;
            $setId = DB::table('homepage_offer_sets')->insertGetId(['row_type' => $rowType, 'name' => 'Default '.ucfirst($rowType).' offers', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('homepage_offer_blocks')->whereIn('id', $blocks->pluck('id'))->update(['offer_set_id' => $setId]);
        }
    }
    public function down(): void
    {
        Schema::table('homepage_offer_blocks', function (Blueprint $table) { $table->dropUnique(['offer_set_id','slot']); $table->dropConstrainedForeignId('offer_set_id'); $table->unique(['row_type','slot']); });
        Schema::dropIfExists('homepage_offer_sets');
    }
};
