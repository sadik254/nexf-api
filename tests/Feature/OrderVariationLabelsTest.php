<?php

namespace Tests\Feature;

use App\Http\Controllers\OrderController;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
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

    public function test_variation_label_backfill_uses_primary_key_columns_on_query_builder(): void
    {
        $category = ProductCategory::create(['name' => 'Backfill coats', 'slug' => 'backfill-coats']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Backfill coat',
            'slug' => 'backfill-coat',
            'product_type' => 'variable',
            'status' => 'active',
            'option_groups' => [[
                'key' => 'vg-1',
                'label' => 'Color',
                'values' => [['value' => 'white', 'label' => 'White', 'swatch' => '#fff']],
            ]],
        ]);
        $customer = Customer::create(['name' => 'Customer', 'email' => 'label-backfill@example.test', 'password' => 'password123']);
        $order = Order::create(['order_number' => 'LABEL-BACKFILL', 'customer_id' => $customer->id, 'status' => 'delivered', 'payment_status' => 'paid', 'subtotal' => 100, 'total' => 100, 'shipping_name' => 'Customer', 'shipping_phone' => '01700000000', 'shipping_address' => 'Dhaka']);
        $item = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 1, 'unit_selling_price' => 100, 'unit_buying_price' => 50, 'line_subtotal' => 100, 'line_cost' => 50, 'line_profit' => 50, 'fulfillment_status' => 'delivered', 'variation_attributes' => ['vg-1' => 'white'], 'variation_labels' => [['group' => 'vg-1', 'value' => 'white', 'swatch' => null]]]);

        $migration = require database_path('migrations/2026_10_30_000001_correct_order_variation_labels.php');
        $migration->up();

        $this->assertSame([['group' => 'Color', 'value' => 'White', 'swatch' => '#fff']], $item->fresh()->variation_labels);
    }
}
