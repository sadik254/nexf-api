<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_collections', function (Blueprint $table): void {
            $table->string('slug', 180)->nullable()->after('name');
        });

        $used = [];
        DB::table('product_collections')->orderBy('id')->select(['id', 'name'])->each(function (object $collection) use (&$used): void {
            $base = Str::slug($collection->name) ?: 'collection';
            $slug = $base;
            $suffix = 2;
            while (isset($used[$slug])) {
                $ending = '-'.$suffix++;
                $slug = Str::substr($base, 0, 180 - Str::length($ending)).$ending;
            }
            $used[$slug] = true;
            DB::table('product_collections')->where('id', $collection->id)->update(['slug' => $slug]);
        });

        Schema::table('product_collections', function (Blueprint $table): void {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('product_collections', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
