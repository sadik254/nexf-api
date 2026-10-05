<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLot;
use App\Models\ProductVariation;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductListFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_tabs_and_seller_scope_filter_before_pagination(): void
    {
        $admin = Admin::create(['name' => 'Owner', 'email' => 'list-owner@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'list-clothing']);
        $seller = Seller::create(['seller_name' => 'Seller', 'email' => 'list-seller@example.test', 'store_name' => 'Seller Store', 'store_slug' => 'list-seller', 'kyc_type' => 'nid', 'kyc_number' => '1', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);

        $house = Product::create(['category_id' => $category->id, 'name' => 'House shirt', 'slug' => 'house-shirt', 'product_type' => 'simple', 'status' => 'active']);
        ProductLot::create(['product_id' => $house->id, 'lot_number' => 'H1', 'buying_price' => 10, 'selling_price' => 20, 'quantity' => 25, 'quantity_remaining' => 25]);
        $low = Product::create(['seller_id' => $seller->id, 'category_id' => $category->id, 'name' => 'Seller coat', 'slug' => 'seller-coat', 'product_type' => 'variable', 'status' => 'active']);
        $variation = ProductVariation::create(['product_id' => $low->id, 'attributes' => ['Color' => 'Pink'], 'is_active' => true]);
        ProductLot::create(['product_id' => $low->id, 'variation_id' => $variation->id, 'lot_number' => 'S1', 'buying_price' => 10, 'selling_price' => 20, 'quantity' => 3, 'quantity_remaining' => 3]);
        Product::create(['seller_id' => $seller->id, 'category_id' => $category->id, 'name' => 'Seller empty', 'slug' => 'seller-empty', 'product_type' => 'simple', 'status' => 'draft']);

        $this->withToken($token)->getJson('/api/admin/products?scope=all&stock=low&per_page=1')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $low->id)
            ->assertJsonPath('stock_counts.ok', 1)->assertJsonPath('stock_counts.low', 1)->assertJsonPath('stock_counts.out', 1);
        $this->withToken($token)->getJson("/api/admin/products?scope=all&seller_id={$seller->id}&stock=out")
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('stock_counts.ok', 0)->assertJsonPath('stock_counts.low', 1);
        $this->withToken($token)->getJson('/api/admin/products?stock=ok')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $house->id);
        $this->withToken($token)->getJson('/api/admin/products?scope=all&search=Seller%20Store')
            ->assertOk()->assertJsonPath('total', 2)->assertJsonPath('stock_counts.ok', 1);
        $basic = Admin::create(['name' => 'Staff', 'email' => 'list-staff@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $basicToken = $basic->createToken('test', ['admin:basic'])->plainTextToken;
        $this->withToken($basicToken)->getJson('/api/admin/products?scope=all')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $house->id);
    }
}
