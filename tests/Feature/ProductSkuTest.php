<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSkuTest extends TestCase
{
    use RefreshDatabase;

    public function test_simple_product_sku_persists_on_create_and_update_and_must_be_unique(): void
    {
        $admin = Admin::create([
            'name' => 'Catalog Admin',
            'email' => 'catalog-sku@example.test',
            'password' => 'password123',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'sku-clothing']);

        $first = $this->withToken($token)->postJson('/api/admin/products', [
            'category_id' => $category->id,
            'name' => 'Cotton Shirt',
            'sku' => ' NEXF-SHIRT-01 ',
            'product_type' => 'simple',
            'status' => 'draft',
        ])->assertCreated()->assertJsonPath('product.sku', 'NEXF-SHIRT-01')->json('product.id');

        $this->assertSame('NEXF-SHIRT-01', Product::findOrFail($first)->sku);
        $this->withToken($token)->postJson("/api/admin/products/{$first}", [
            'sku' => 'NEXF-SHIRT-02',
        ])->assertOk()->assertJsonPath('product.sku', 'NEXF-SHIRT-02');

        $this->withToken($token)->postJson('/api/admin/products', [
            'category_id' => $category->id,
            'name' => 'Linen Shirt',
            'sku' => 'NEXF-SHIRT-02',
            'product_type' => 'simple',
            'status' => 'draft',
        ])->assertUnprocessable()->assertJsonValidationErrors('sku');
    }
}
