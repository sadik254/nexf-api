<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Seller;
use App\Models\ProductCategory;
use App\Services\MediaUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class MediaAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_assets_are_persistent_and_seller_scoped(): void
    {
        $first = Seller::create(['seller_name' => 'First', 'email' => 'first-media@example.test', 'store_name' => 'First Store', 'store_slug' => 'first-media', 'kyc_type' => 'nid', 'kyc_number' => '1', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $second = Seller::create(['seller_name' => 'Second', 'email' => 'second-media@example.test', 'store_name' => 'Second Store', 'store_slug' => 'second-media', 'kyc_type' => 'nid', 'kyc_number' => '2', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $firstToken = $first->createToken('test', ['seller:basic'])->plainTextToken;
        $secondToken = $second->createToken('test', ['seller:basic'])->plainTextToken;

        $id = $this->withToken($firstToken)->postJson('/api/seller/media/link', ['url' => 'https://example.test/image.jpg', 'file_name' => 'image.jpg'])->assertCreated()->assertJsonPath('mime_type', 'image/jpeg')->json('id');
        $this->withToken($firstToken)->getJson('/api/seller/media')->assertOk()->assertJsonPath('total', 1);
        $this->withToken($secondToken)->getJson('/api/seller/media')->assertOk()->assertJsonPath('total', 0);
        $this->withToken($secondToken)->postJson("/api/seller/media/{$id}", ['alt_text' => 'Stolen'])->assertNotFound();

        $this->mock(MediaUploadService::class)->shouldReceive('upload')->once()->andReturn('https://ucarecdn.com/test/-/preview/');
        $this->withToken($firstToken)->post('/api/seller/media', ['file' => UploadedFile::fake()->image('coat.jpg')])
            ->assertCreated()->assertJsonPath('url', 'https://ucarecdn.com/test/-/preview/');

        $admin = Admin::create(['name' => 'Super', 'email' => 'super-media@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $adminToken = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $this->withToken($adminToken)->getJson('/api/admin/media')->assertOk()->assertJsonPath('total', 2);
        $this->withToken($adminToken)->postJson("/api/admin/media/{$id}/delete")->assertOk();
        $this->withToken($firstToken)->getJson('/api/seller/media')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_seller_can_select_own_library_image_for_product_but_not_another_sellers(): void
    {
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'media-clothing']);
        $seller = Seller::create(['seller_name' => 'Seller', 'email' => 'product-media@example.test', 'store_name' => 'Product Media', 'store_slug' => 'product-media', 'kyc_type' => 'nid', 'kyc_number' => '3', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $other = Seller::create(['seller_name' => 'Other', 'email' => 'other-media@example.test', 'store_name' => 'Other Media', 'store_slug' => 'other-media', 'kyc_type' => 'nid', 'kyc_number' => '4', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $mine = \App\Models\MediaAsset::create(['owner_type' => 'seller', 'owner_id' => $seller->id, 'source' => 'url', 'url' => 'https://example.test/mine.jpg', 'file_name' => 'mine.jpg', 'mime_type' => 'image/jpeg']);
        $theirs = \App\Models\MediaAsset::create(['owner_type' => 'seller', 'owner_id' => $other->id, 'source' => 'url', 'url' => 'https://example.test/theirs.jpg', 'file_name' => 'theirs.jpg', 'mime_type' => 'image/jpeg']);
        $token = $seller->createToken('test', ['seller:basic'])->plainTextToken;
        $base = ['category_id' => $category->id, 'name' => 'Media coat', 'product_type' => 'simple', 'status' => 'draft'];
        $this->withToken($token)->postJson('/api/seller/products', $base + ['thumbnail_media_id' => $theirs->id])->assertForbidden();
        $this->withToken($token)->postJson('/api/seller/products', $base + ['thumbnail_media_id' => $mine->id, 'gallery_media_ids' => [$mine->id]])
            ->assertCreated()->assertJsonPath('product.thumbnail', 'https://example.test/mine.jpg')->assertJsonPath('product.gallery.0', 'https://example.test/mine.jpg');

        $secondImage = \App\Models\MediaAsset::create(['owner_type' => 'seller', 'owner_id' => $seller->id, 'source' => 'url', 'url' => 'https://example.test/second.jpg', 'file_name' => 'second.jpg', 'mime_type' => 'image/jpeg']);
        $product = \App\Models\Product::where('seller_id', $seller->id)->firstOrFail();
        $this->withToken($token)->postJson("/api/seller/products/{$product->id}", [
            'thumbnail_media_id' => $secondImage->id,
            'gallery_order' => [['url' => 'https://example.test/mine.jpg']],
        ])->assertOk()->assertJsonPath('product.thumbnail', 'https://example.test/second.jpg')->assertJsonPath('product.gallery.0', 'https://example.test/mine.jpg');
        $this->withToken($token)->postJson("/api/seller/products/{$product->id}", [
            'gallery_order' => [['url' => 'https://example.test/unrelated.jpg']],
        ])->assertUnprocessable();
        $this->withToken($token)->postJson("/api/seller/products/{$product->id}", [
            'gallery_order' => [['media_id' => $theirs->id]],
        ])->assertForbidden();
        $this->withToken($token)->postJson("/api/seller/products/{$product->id}", [
            'clear_gallery' => true,
            'default_selling_price' => 499,
            'compare_at_price' => 699,
            'weight_kg' => 0.8,
        ])->assertOk()->assertJsonPath('product.gallery', [])
            ->assertJsonPath('product.default_selling_price', 499)
            ->assertJsonPath('product.compare_at_price', '699.00');
    }
}
