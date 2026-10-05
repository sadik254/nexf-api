<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCollectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_collections_are_scoped_and_cannot_include_another_stores_products(): void
    {
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']);
        $first = Seller::create(['seller_name' => 'First', 'email' => 'first-col@example.test', 'store_name' => 'First Store', 'store_slug' => 'first-store', 'kyc_type' => 'nid', 'kyc_number' => '1', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $second = Seller::create(['seller_name' => 'Second', 'email' => 'second-col@example.test', 'store_name' => 'Second Store', 'store_slug' => 'second-store', 'kyc_type' => 'nid', 'kyc_number' => '2', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $one = Product::create(['seller_id' => $first->id, 'category_id' => $category->id, 'name' => 'One', 'slug' => 'one']);
        $two = Product::create(['seller_id' => $second->id, 'category_id' => $category->id, 'name' => 'Two', 'slug' => 'two']);
        $platform = Product::create(['category_id' => $category->id, 'name' => 'Platform', 'slug' => 'platform']);
        $firstToken = $first->createToken('test', ['seller:basic'])->plainTextToken;
        $secondToken = $second->createToken('test', ['seller:basic'])->plainTextToken;

        $this->withToken($firstToken)->postJson('/api/seller/collections', ['name' => 'Invalid', 'product_ids' => [$one->id, $two->id]])->assertUnprocessable();
        $this->withToken($firstToken)->postJson('/api/seller/collections', ['name' => 'Invalid', 'product_ids' => [$platform->id]])->assertUnprocessable();
        $id = $this->withToken($firstToken)->postJson('/api/seller/collections', ['name' => 'Fall picks', 'product_ids' => [$one->id]])->assertCreated()->assertJsonPath('products.0.id', $one->id)->json('id');
        $this->withToken($secondToken)->getJson("/api/seller/collections/{$id}")->assertNotFound();
        $this->withToken($secondToken)->getJson('/api/seller/collections')->assertOk()->assertJsonPath('total', 0);
        $this->withToken($firstToken)->getJson('/api/seller/collection-products')->assertOk()->assertJsonPath('total', 1);

        $super = Admin::create(['name' => 'Super', 'email' => 'super-col@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $superToken = $super->createToken('test', ['admin:basic'])->plainTextToken;
        $this->withToken($superToken)->getJson('/api/admin/collections')->assertOk()->assertJsonPath('total', 1);
        $this->withToken($superToken)->getJson('/api/admin/collection-products?seller_id=platform')->assertOk()->assertJsonPath('total', 1);
        $this->withToken($superToken)->postJson('/api/admin/collections', ['name' => 'All stores', 'seller_id' => null, 'product_ids' => [$one->id, $two->id]])->assertCreated()->assertJsonCount(2, 'products');
        $this->withToken($superToken)->postJson("/api/admin/collections/{$id}", ['name' => 'Updated', 'product_ids' => [$one->id]])->assertOk()->assertJsonPath('name', 'Updated');
    }
}
