<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_seller_dashboards_return_correct_scoped_aggregates(): void
    {
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']);
        $customer = Customer::create(['name' => 'Buyer', 'email' => 'buyer-dashboard@example.test', 'password' => 'password123']);
        $seller = Seller::create([
            'seller_name' => 'Seller', 'email' => 'seller-dashboard@example.test', 'store_name' => 'Seller Store',
            'store_slug' => 'seller-store', 'kyc_type' => 'nid', 'kyc_number' => '123',
            'kyc_document_url' => 'https://example.test/kyc', 'product_category' => 'Clothing',
            'status' => 'approved', 'is_active' => true, 'password' => 'password123',
        ]);
        $admin = Admin::create(['name' => 'Admin', 'email' => 'admin-dashboard@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $house = Product::create(['category_id' => $category->id, 'name' => 'House Product', 'slug' => 'house-product', 'product_type' => 'simple', 'status' => 'active']);
        $sellerProduct = Product::create(['seller_id' => $seller->id, 'category_id' => $category->id, 'name' => 'Seller Product', 'slug' => 'seller-product', 'product_type' => 'simple', 'status' => 'active']);
        $order = Order::create([
            'order_number' => 'DASH-1', 'customer_id' => $customer->id, 'status' => 'confirmed',
            'payment_status' => 'paid', 'subtotal' => 300, 'total' => 380, 'shipping_charge' => 80,
            'shipping_name' => 'Buyer', 'shipping_phone' => '01700000000', 'shipping_address' => 'Dhaka',
        ]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $house->id, 'product_name' => $house->name, 'product_slug' => $house->slug, 'quantity' => 1, 'unit_selling_price' => 100, 'unit_buying_price' => 50, 'line_subtotal' => 100, 'line_cost' => 50, 'line_profit' => 50, 'fulfillment_status' => 'confirmed']);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $sellerProduct->id, 'seller_id' => $seller->id, 'product_name' => $sellerProduct->name, 'product_slug' => $sellerProduct->slug, 'quantity' => 2, 'unit_selling_price' => 100, 'unit_buying_price' => 50, 'line_subtotal' => 200, 'line_cost' => 100, 'line_profit' => 100, 'fulfillment_status' => 'confirmed']);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();
        $adminToken = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $this->withToken($adminToken)->getJson("/api/admin/dashboard?from={$from}&to={$to}")
            ->assertOk()->assertJsonPath('stats.revenue', 380)->assertJsonPath('stats.orders', 1)
            ->assertJsonPath('stats.units_sold', 3)->assertJsonPath('top_products.0.name', 'Seller Product')
            ->assertJsonPath('sales_breakdown.gross_sales', 300)->assertJsonPath('sales_breakdown.shipping', 80)
            ->assertJsonPath('top_categories.0.name', 'Clothing');

        $this->withToken($adminToken)->getJson("/api/admin/dashboard?from={$from}&to={$to}&seller_id={$seller->id}")
            ->assertOk()->assertJsonPath('stats.revenue', 200)->assertJsonPath('stats.orders', 1)
            ->assertJsonPath('stats.units_sold', 2)->assertJsonPath('stats.products', 1)
            ->assertJsonPath('top_products.0.name', 'Seller Product')->assertJsonCount(0, 'top_sellers');

        $sellerToken = $seller->createToken('test', ['seller:basic'])->plainTextToken;
        $this->withToken($sellerToken)->getJson("/api/seller/dashboard?from={$from}&to={$to}")
            ->assertOk()->assertJsonPath('stats.revenue', 200)->assertJsonPath('stats.orders', 1)
            ->assertJsonPath('stats.units_sold', 2)->assertJsonPath('top_products.0.name', 'Seller Product')
            ->assertJsonPath('sales_breakdown.gross_sales', 200)->assertJsonPath('sales_breakdown.profit', 100)
            ->assertJsonCount(0, 'top_sellers');
    }
}
