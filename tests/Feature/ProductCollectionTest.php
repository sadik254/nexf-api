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

    public function test_collections_keep_ordered_top_level_photo_categories_and_expose_public_metadata(): void
    {
        $women = ProductCategory::create(['name' => 'Photo Women', 'slug' => 'photo-women']);
        $men = ProductCategory::create(['name' => 'Photo Men', 'slug' => 'photo-men']);
        $hoodies = ProductCategory::create(['name' => 'Photo Hoodies', 'slug' => 'photo-women-hoodies', 'parent_id' => $women->id]);
        $product = Product::create(['category_id' => $hoodies->id, 'name' => 'Classic Hoodie', 'slug' => 'classic-hoodie', 'status' => 'active', 'base_selling_price' => 1200]);
        $admin = Admin::create(['name' => 'Super', 'email' => 'collection-photos@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;

        $id = $this->withToken($token)->postJson('/api/admin/collections', [
            'name' => 'Winter favourites',
            'product_ids' => [$product->id],
            'photo_category_ids' => [$women->id, $men->id],
        ])->assertCreated()
            ->assertJsonPath('photo_category_ids', [$women->id, $men->id])
            ->assertJsonPath('photo_categories.0.slug', 'photo-women')
            ->assertJsonPath('photo_categories.1.slug', 'photo-men')
            ->json('id');

        $this->getJson("/api/store/collections/{$id}")
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('photo_categories.0', ['id' => $women->id, 'slug' => 'photo-women'])
            ->assertJsonPath('photo_categories.1', ['id' => $men->id, 'slug' => 'photo-men']);

        $this->withToken($token)->postJson("/api/admin/collections/{$id}", [
            'name' => 'Winter favourites',
            'product_ids' => [$product->id],
            'photo_category_ids' => [],
        ])->assertOk()
            ->assertJsonPath('photo_category_ids', [])
            ->assertJsonPath('photo_categories', []);

        $this->withToken($token)->postJson('/api/admin/collections', [
            'name' => 'Invalid photos',
            'product_ids' => [$product->id],
            'photo_category_ids' => [$hoodies->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('photo_category_ids');
    }

    public function test_seller_collections_are_scoped_and_cannot_include_another_stores_products(): void
    {
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']);
        $first = Seller::create(['seller_name' => 'First', 'email' => 'first-col@example.test', 'store_name' => 'First Store', 'store_slug' => 'first-store', 'kyc_type' => 'nid', 'kyc_number' => '1', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $second = Seller::create(['seller_name' => 'Second', 'email' => 'second-col@example.test', 'store_name' => 'Second Store', 'store_slug' => 'second-store', 'kyc_type' => 'nid', 'kyc_number' => '2', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $one = Product::create(['seller_id' => $first->id, 'category_id' => $category->id, 'name' => 'One', 'slug' => 'one', 'status' => 'active']);
        $two = Product::create(['seller_id' => $second->id, 'category_id' => $category->id, 'name' => 'Two', 'slug' => 'two']);
        $platform = Product::create(['category_id' => $category->id, 'name' => 'Platform', 'slug' => 'platform']);
        $firstToken = $first->createToken('test', ['seller:basic'])->plainTextToken;
        $secondToken = $second->createToken('test', ['seller:basic'])->plainTextToken;

        $this->withToken($firstToken)->postJson('/api/seller/collections', ['name' => 'Invalid', 'product_ids' => [$one->id, $two->id]])->assertUnprocessable();
        $this->withToken($firstToken)->postJson('/api/seller/collections', ['name' => 'Invalid', 'product_ids' => [$platform->id]])->assertUnprocessable();
        $created = $this->withToken($firstToken)->postJson('/api/seller/collections', ['name' => 'Fall picks', 'product_ids' => [$one->id]])->assertCreated()->assertJsonPath('products.0.id', $one->id)->assertJsonPath('slug', 'fall-picks')->json();
        $id = $created['id'];
        $this->getJson('/api/store/products?collection='.$id)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $one->id);
        $this->getJson('/api/store/products?collection=fall-picks')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/store/collections/fall-picks')->assertOk()->assertJsonPath('id', $id)->assertJsonPath('slug', 'fall-picks');
        $this->withToken($secondToken)->getJson("/api/seller/collections/{$id}")->assertNotFound();
        $this->withToken($secondToken)->getJson('/api/seller/collections')->assertOk()->assertJsonPath('total', 0);
        $this->withToken($firstToken)->getJson('/api/seller/collection-products')->assertOk()->assertJsonPath('total', 1);

        $super = Admin::create(['name' => 'Super', 'email' => 'super-col@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $superToken = $super->createToken('test', ['admin:basic'])->plainTextToken;
        $this->withToken($superToken)->getJson('/api/admin/collections')->assertOk()->assertJsonPath('total', 1);
        $this->withToken($superToken)->getJson('/api/admin/collection-products?seller_id=platform')->assertOk()->assertJsonPath('total', 1);
        $this->withToken($superToken)->postJson('/api/admin/collections', ['name' => 'All stores', 'seller_id' => null, 'product_ids' => [$one->id, $two->id]])->assertCreated()->assertJsonCount(2, 'products');
        $this->withToken($superToken)->postJson("/api/admin/collections/{$id}", ['name' => 'Updated', 'product_ids' => [$one->id]])->assertOk()->assertJsonPath('name', 'Updated')->assertJsonPath('slug', 'updated');
    }

    public function test_duplicate_collection_names_receive_numbered_unique_slugs(): void
    {
        $category = ProductCategory::create(['name' => 'Slugs', 'slug' => 'slugs']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Product', 'slug' => 'collection-slug-product', 'status' => 'active']);
        $admin = Admin::create(['name' => 'Super', 'email' => 'collection-slugs@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;

        $this->withToken($token)->postJson('/api/admin/collections', ['name' => 'Winter Collection', 'product_ids' => [$product->id]])->assertCreated()->assertJsonPath('slug', 'winter-collection');
        $this->withToken($token)->postJson('/api/admin/collections', ['name' => 'Winter Collection', 'product_ids' => [$product->id]])->assertCreated()->assertJsonPath('slug', 'winter-collection-2');
    }
}
