<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('products')->whereNull('default_selling_price')->orderBy('id')->chunkById(200, function ($products) {
            foreach ($products as $product) {
                if ($product->product_type !== 'simple') continue;
                $lots = DB::table('product_lots')->where('product_id', $product->id)->whereNull('variation_id');
                $price = (clone $lots)->where('quantity_remaining', '>', 0)
                    ->orderByRaw('received_at is null')->orderBy('received_at')->orderBy('id')->value('selling_price');
                if ($price === null) $price = $lots->orderByRaw('received_at is null')->orderBy('received_at')->orderBy('id')->value('selling_price');
                if ($price !== null) DB::table('products')->where('id', $product->id)->update(['default_selling_price' => $price]);
            }
        });
        DB::table('product_variations')->whereNull('default_selling_price')->orderBy('id')->chunkById(200, function ($variations) {
            foreach ($variations as $variation) {
                $lots = DB::table('product_lots')->where('variation_id', $variation->id);
                $price = (clone $lots)->where('quantity_remaining', '>', 0)
                    ->orderByRaw('received_at is null')->orderBy('received_at')->orderBy('id')->value('selling_price');
                if ($price === null) $price = $lots->orderByRaw('received_at is null')->orderBy('received_at')->orderBy('id')->value('selling_price');
                if ($price !== null) DB::table('product_variations')->where('id', $variation->id)->update(['default_selling_price' => $price]);
            }
        });
    }

    public function down(): void {}
};
