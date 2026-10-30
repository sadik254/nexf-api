<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('order_items')->whereNotNull('variation_labels')->whereNotNull('variation_attributes')->orderBy('id')->chunkById(100, function ($items): void {
            foreach ($items as $item) {
                $product = $item->product_id ? DB::table('products')->select('option_groups')->where('id', $item->product_id)->first() : null;
                if (! $product) continue;
                $attributes = json_decode($item->variation_attributes ?: '{}', true) ?: [];
                $groups = json_decode($product->option_groups ?: '[]', true) ?: [];
                $labels = [];
                $handled = [];
                foreach ($groups as $group) {
                    $key = (string) ($group['key'] ?? '');
                    if ($key === '' || !array_key_exists($key, $attributes)) continue;
                    $selected = collect($group['values'] ?? [])->first(fn ($value) => (string) ($value['value'] ?? '') === (string) $attributes[$key]);
                    $labels[] = ['group' => $group['label'] ?? $key, 'value' => $selected['label'] ?? (string) $attributes[$key], 'swatch' => $selected['swatch'] ?? null];
                    $handled[] = $key;
                }
                foreach ($attributes as $key => $value) if (!in_array((string) $key, $handled, true)) $labels[] = ['group' => (string) $key, 'value' => (string) $value, 'swatch' => null];
                DB::table('order_items')->where('id', $item->id)->update(['variation_labels' => json_encode($labels)]);
            }
        });
    }

    public function down(): void {}
};
