<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSearchListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unlisted_products_are_saved_and_accessible_only_by_direct_link(): void
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => 'unlisted@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'unlisted-clothing']);
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $id = $this->withToken($token)->postJson('/api/admin/products', [
            'category_id' => $category->id, 'name' => 'Private Coat', 'slug' => 'private-coat',
            'product_type' => 'simple', 'status' => 'unlisted',
            'specification_tables' => [['title' => 'Notes', 'rows' => [['label' => '', 'value' => 'Cotton'], ['label' => 'Care', 'value' => ''], ['label' => '', 'value' => '']]]],
            'specifications' => [['label' => '', 'value' => 'Cotton']],
        ])->assertCreated()->assertJsonPath('product.status', 'unlisted')
            ->assertJsonPath('product.specification_tables.0.rows.0.label', '')
            ->assertJsonPath('product.specification_tables.0.rows.1.value', '')
            ->assertJsonCount(2, 'product.specification_tables.0.rows')->json('product.id');
        $this->withToken($token)->postJson("/api/admin/products/{$id}", ['specifications' => [null]])->assertUnprocessable();
        $this->withToken($token)->postJson("/api/admin/products/{$id}", ['specification_tables' => [['rows' => [null]]]])->assertUnprocessable();
        $this->getJson('/api/store/products/private-coat')->assertOk()->assertJsonPath('id', $id);
        $this->getJson('/api/store/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/store/products?search=Private')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->postJson("/api/admin/products/{$id}", ['status' => 'active'])->assertOk();
        $this->getJson('/api/store/products')->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->postJson("/api/admin/products/{$id}", ['status' => 'draft'])->assertOk();
        $this->getJson('/api/store/products/private-coat')->assertNotFound();
        $this->withToken($token)->postJson("/api/admin/products/{$id}", ['status' => 'inactive'])->assertOk();
        $this->getJson('/api/store/products/private-coat')->assertNotFound();
    }

    public function test_product_search_listing_fields_are_saved_and_public(): void
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => 'product-seo@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing-seo']);
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $id = $this->withToken($token)->postJson('/api/admin/products', [
            'category_id' => $category->id, 'name' => 'Pink Coat', 'slug' => 'pink-coat-custom',
            'seo_title' => 'Pink Coat | NEXF Lifestyle', 'seo_description' => 'Shop the Pink Coat.',
            'specifications' => [['label' => 'Material', 'value' => 'Cotton']],
            'product_type' => 'simple', 'status' => 'active',
        ])->assertCreated()->assertJsonPath('product.slug', 'pink-coat-custom')->json('product.id');
        $this->getJson('/api/store/products/pink-coat-custom')->assertOk()->assertJsonPath('seo_title', 'Pink Coat | NEXF Lifestyle');
        $this->withToken($token)->postJson("/api/admin/products/{$id}", ['slug' => 'pink-coat-new', 'seo_title' => 'New title', 'clear_specifications' => true])->assertOk()->assertJsonPath('product.slug', 'pink-coat-new')->assertJsonPath('product.specifications', []);
        $this->getJson('/api/store/products/pink-coat-new')->assertOk()->assertJsonPath('seo_title', 'New title');
        $this->withToken($token)->postJson('/api/admin/products', ['category_id' => $category->id, 'name' => 'Another', 'slug' => 'pink-coat-new', 'product_type' => 'simple'])->assertUnprocessable();
    }
}
