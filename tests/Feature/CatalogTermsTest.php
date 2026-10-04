<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTermsTest extends TestCase
{
    use RefreshDatabase;

    public function test_brands_and_tags_are_live_product_metadata_with_scoped_management(): void
    {
        $super = Admin::create(['name' => 'Super', 'email' => 'super-catalog@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $admin = Admin::create(['name' => 'Admin', 'email' => 'admin-catalog@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $seller = Seller::create(['seller_name' => 'Seller', 'email' => 'seller-catalog@example.test', 'store_name' => 'Seller Store', 'store_slug' => 'seller-catalog', 'kyc_type' => 'nid', 'kyc_number' => '123', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'catalog-clothing']);

        $superToken = $super->createToken('test', ['admin:basic'])->plainTextToken;
        $adminToken = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $sellerToken = $seller->createToken('test', ['seller:basic'])->plainTextToken;

        $brandId = $this->withToken($superToken)->postJson('/api/admin/brands', ['name' => 'NEXF Original'])->assertCreated()->json('id');
        $tagId = $this->withToken($superToken)->postJson('/api/admin/tags', ['name' => 'Featured'])->assertCreated()->json('id');
        $this->withToken($adminToken)->postJson('/api/admin/brands', ['name' => 'Forbidden'])->assertForbidden();
        $this->getJson('/api/store/brands')->assertOk()->assertJsonPath('0.slug', 'nexf-original');

        $productId = $this->withToken($sellerToken)->postJson('/api/seller/products', [
            'category_id' => $category->id, 'brand_id' => $brandId, 'tag_ids' => [$tagId],
            'name' => 'Tagged Jacket', 'product_type' => 'simple', 'status' => 'active',
        ])->assertCreated()->json('product.id');

        $this->assertSame($brandId, Product::findOrFail($productId)->brand_id);
        $this->withToken($sellerToken)->getJson("/api/seller/products/{$productId}")->assertOk()
            ->assertJsonPath('brand.name', 'NEXF Original')->assertJsonPath('tags.0.slug', 'featured');
        $this->getJson('/api/store/products/tagged-jacket')->assertOk()
            ->assertJsonPath('brand', 'NEXF Original')->assertJsonPath('tags.0', 'featured');
        $this->getJson('/api/store/products?brand=nexf-original&tag=featured')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/store/products?tag=unknown')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/store/products?search=Featured')->assertOk()->assertJsonPath('total', 1);
        $this->withToken($superToken)->postJson("/api/admin/brands/{$brandId}/delete")->assertUnprocessable();
        $this->withToken($sellerToken)->postJson("/api/seller/products/{$productId}", ['tag_ids' => []])->assertOk();
        $this->withToken($superToken)->postJson("/api/admin/tags/{$tagId}/delete")->assertOk();
        $this->withToken($sellerToken)->post("/api/seller/products/{$productId}", ['brand_id' => '', 'clear_tags' => '1'])->assertOk();
        $this->assertNull(Product::findOrFail($productId)->brand_id);
    }
}
