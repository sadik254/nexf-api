<?php

namespace Tests\Feature;

use App\Http\Controllers\OrderController;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderVariationLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_snapshot_uses_visible_option_labels_without_raw_key_duplicates(): void
    {
        $category = ProductCategory::create(['name' => 'Coats', 'slug' => 'coats']);
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Coat', 'slug' => 'coat', 'product_type' => 'variable', 'status' => 'active',
            'option_groups' => [[
                'key' => 'vg-1', 'label' => 'Color', 'display_type' => 'swatch',
                'values' => [['value' => 'white', 'label' => 'White', 'swatch' => '#ffffff']],
            ]],
        ]);
        $variation = ProductVariation::create(['product_id' => $product->id, 'attributes' => ['vg-1' => 'white'], 'is_active' => true]);
        $method = new \ReflectionMethod(OrderController::class, 'variationLabels');
        $method->setAccessible(true);

        $this->assertSame([['group' => 'Color', 'value' => 'White', 'swatch' => '#ffffff']], $method->invoke(app(OrderController::class), $product, $variation));
    }
}
