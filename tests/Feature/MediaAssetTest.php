<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Seller;
use App\Models\SellerStaff;
use App\Services\MediaUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        $mine = MediaAsset::create(['owner_type' => 'seller', 'owner_id' => $seller->id, 'source' => 'url', 'url' => 'https://example.test/mine.jpg', 'file_name' => 'mine.jpg', 'mime_type' => 'image/jpeg']);
        $theirs = MediaAsset::create(['owner_type' => 'seller', 'owner_id' => $other->id, 'source' => 'url', 'url' => 'https://example.test/theirs.jpg', 'file_name' => 'theirs.jpg', 'mime_type' => 'image/jpeg']);
        $token = $seller->createToken('test', ['seller:basic'])->plainTextToken;
        $base = ['category_id' => $category->id, 'name' => 'Media coat', 'product_type' => 'simple', 'status' => 'draft'];
        $this->withToken($token)->postJson('/api/seller/products', $base + ['thumbnail_media_id' => $theirs->id])->assertForbidden();
        $this->withToken($token)->postJson('/api/seller/products', $base + ['thumbnail_media_id' => $mine->id, 'gallery_media_ids' => [$mine->id]])
            ->assertCreated()->assertJsonPath('product.thumbnail', 'https://example.test/mine.jpg')->assertJsonPath('product.gallery.0', 'https://example.test/mine.jpg');

        $secondImage = MediaAsset::create(['owner_type' => 'seller', 'owner_id' => $seller->id, 'source' => 'url', 'url' => 'https://example.test/second.jpg', 'file_name' => 'second.jpg', 'mime_type' => 'image/jpeg']);
        $product = Product::where('seller_id', $seller->id)->firstOrFail();
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

    public function test_admin_can_create_a_store_product_with_that_stores_library_image(): void
    {
        $category = ProductCategory::create(['name' => 'Staff catalogue', 'slug' => 'staff-catalogue']);
        $seller = Seller::create(['seller_name' => 'Store owner', 'email' => 'staff-product-store@example.test', 'store_name' => 'Staff product store', 'store_slug' => 'staff-product-store', 'kyc_type' => 'nid', 'kyc_number' => '91', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $admin = Admin::create(['name' => 'Super', 'email' => 'staff-product-admin@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $asset = MediaAsset::create(['owner_type' => 'seller', 'owner_id' => $seller->id, 'source' => 'url', 'url' => 'https://example.test/store-file.jpg', 'file_name' => 'store-file.jpg', 'mime_type' => 'image/jpeg']);
        $asset->storeLibraries()->create(['seller_id' => $seller->id, 'library_key' => "seller:{$seller->id}"]);

        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/products', [
            'seller_id' => $seller->id, 'category_id' => $category->id, 'name' => 'Admin created store product', 'product_type' => 'simple', 'status' => 'draft',
            'thumbnail_media_id' => $asset->id, 'gallery_media_ids' => [$asset->id],
        ])->assertCreated()->assertJsonPath('product.thumbnail', $asset->url);
    }

    public function test_an_asset_can_be_shared_with_many_store_libraries_and_staff_can_remove_only_their_store_copy(): void
    {
        $first = Seller::create(['seller_name' => 'First', 'email' => 'first-shared-media@example.test', 'store_name' => 'First Store', 'store_slug' => 'first-shared-media', 'kyc_type' => 'nid', 'kyc_number' => '11', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $second = Seller::create(['seller_name' => 'Second', 'email' => 'second-shared-media@example.test', 'store_name' => 'Second Store', 'store_slug' => 'second-shared-media', 'kyc_type' => 'nid', 'kyc_number' => '22', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $admin = Admin::create(['name' => 'Super', 'email' => 'shared-media@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $adminToken = $admin->createToken('test', ['admin'])->plainTextToken;

        $asset = $this->withToken($adminToken)->postJson('/api/admin/media/link', [
            'url' => 'https://example.test/shared.jpg', 'file_name' => 'shared.jpg',
            'seller_ids' => [$first->id, $second->id], 'include_house' => true,
        ])->assertCreated()
            ->assertJsonPath('uploaded_by_type', 'admin')
            ->assertJsonPath('seller_ids', [$first->id, $second->id])
            ->assertJsonPath('include_house', true)
            ->json();

        $firstToken = $first->createToken('test', ['seller'])->plainTextToken;
        $secondToken = $second->createToken('test', ['seller'])->plainTextToken;
        $this->withToken($firstToken)->getJson('/api/seller/media')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $asset['id']);
        $this->withToken($secondToken)->getJson('/api/seller/media')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $asset['id']);

        $staff = SellerStaff::create(['seller_id' => $first->id, 'name' => 'First staff', 'email' => 'first-staff-media@example.test', 'password' => 'password123', 'status' => 'active']);
        $staffToken = $staff->createToken('test', ['seller'])->plainTextToken;
        $this->withToken($staffToken)->postJson("/api/seller/media/{$asset['id']}", ['file_name' => 'renamed-shared.jpg'])
            ->assertOk()->assertJsonPath('file_name', 'renamed-shared.jpg');
        $this->withToken($staffToken)->postJson("/api/seller/media/{$asset['id']}/delete")
            ->assertOk()->assertJsonPath('message', 'Removed from this store media library. The original file remains available for other stores.');

        $this->withToken($firstToken)->getJson('/api/seller/media')->assertOk()->assertJsonPath('total', 0);
        $this->withToken($secondToken)->getJson('/api/seller/media')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.file_name', 'renamed-shared.jpg');
        $this->assertDatabaseHas('media_assets', ['id' => $asset['id']]);
        $this->assertDatabaseMissing('media_asset_store_libraries', ['media_asset_id' => $asset['id'], 'seller_id' => $first->id]);
        $this->assertDatabaseHas('media_asset_store_libraries', ['media_asset_id' => $asset['id'], 'seller_id' => $second->id]);
    }

    public function test_account_and_store_forms_can_use_existing_library_images_without_a_browser_reupload(): void
    {
        $admin = Admin::create(['name' => 'Super', 'email' => 'profile-library@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $asset = MediaAsset::create([
            'owner_type' => 'admin', 'owner_id' => $admin->id, 'uploaded_by_type' => 'admin', 'uploaded_by_id' => $admin->id,
            'uploader_name' => $admin->name, 'source' => 'url', 'url' => 'https://media.example.test/profiles/super.webp',
            'file_name' => 'super.webp', 'mime_type' => 'image/webp',
        ]);
        $asset->storeLibraries()->create(['seller_id' => null, 'library_key' => 'house']);
        $token = $admin->createToken('test', ['admin'])->plainTextToken;

        $this->withToken($token)->postJson('/api/admin/me', ['image_media_id' => $asset->id])
            ->assertOk()->assertJsonPath('admin.image', $asset->url);
        $this->withToken($token)->postJson('/api/admin/house-store', ['store_logo_media_id' => $asset->id])
            ->assertOk()->assertJsonPath('store_logo', $asset->url);
    }

    public function test_svg_uploads_are_sanitized_before_being_sent_to_the_public_media_host(): void
    {
        $admin = Admin::create(['name' => 'Super', 'email' => 'svg-media@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $this->mock(MediaUploadService::class, function ($mock): void {
            $mock->shouldReceive('upload')->once()->withArgs(function ($file, $directory, $mime): bool {
                $contents = file_get_contents($file->getRealPath());
                $this->assertSame('media', $directory);
                $this->assertSame('image/svg+xml', $mime);
                $this->assertStringNotContainsString('<script', strtolower((string) $contents));
                $this->assertStringNotContainsString('javascript:', strtolower((string) $contents));
                $this->assertStringNotContainsString('https://evil.example', strtolower((string) $contents));

                return true;
            })->andReturn('https://media.example.test/media/logo.svg');
        });

        $file = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><a href="javascript:alert(1)"><rect onload="alert(1)"/></a><image href="https://evil.example/logo.png"/></svg>');
        $token = $admin->createToken('test', ['admin'])->plainTextToken;
        $this->withToken($token)->post('/api/admin/media', ['file' => $file])
            ->assertCreated()->assertJsonPath('mime_type', 'image/svg+xml');
    }

    public function test_console_media_file_names_are_global_and_case_insensitive(): void
    {
        $first = Seller::create(['seller_name' => 'First', 'email' => 'global-first@example.test', 'store_name' => 'First Store', 'store_slug' => 'global-first', 'kyc_type' => 'nid', 'kyc_number' => '101', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $second = Seller::create(['seller_name' => 'Second', 'email' => 'global-second@example.test', 'store_name' => 'Second Store', 'store_slug' => 'global-second', 'kyc_type' => 'nid', 'kyc_number' => '102', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $firstToken = $first->createToken('test', ['seller'])->plainTextToken;
        $secondToken = $second->createToken('test', ['seller'])->plainTextToken;

        $this->withToken($firstToken)->postJson('/api/seller/media/link', ['url' => 'https://example.test/one.png', 'file_name' => 'walton-dark.png'])->assertCreated()->assertJsonPath('file_name', 'walton-dark.png');
        $secondAsset = $this->withToken($secondToken)->postJson('/api/seller/media/link', ['url' => 'https://example.test/two.png', 'file_name' => 'walton-dark.png'])
            ->assertCreated()->assertJsonPath('file_name', 'walton-dark-2.png')->json();

        $this->mock(MediaUploadService::class)->shouldReceive('upload')->once()->andReturn('https://example.test/three.png');
        $this->withToken($secondToken)->post('/api/seller/media', ['file' => UploadedFile::fake()->image('walton-dark.png')])
            ->assertCreated()->assertJsonPath('file_name', 'walton-dark-3.png');

        $this->withToken($secondToken)->postJson("/api/seller/media/{$secondAsset['id']}", ['file_name' => 'WALTON-DARK.PNG'])
            ->assertUnprocessable()->assertJsonValidationErrors('file_name');
        $this->withToken($secondToken)->postJson("/api/seller/media/{$secondAsset['id']}", ['file_name' => 'WALTON-DARK-2.PNG'])
            ->assertOk()->assertJsonPath('file_name', 'WALTON-DARK-2.PNG');

        MediaAsset::create(['owner_type' => 'customer', 'owner_id' => 1, 'source' => 'upload', 'url' => 'https://example.test/review.png', 'file_name' => 'review-only.png', 'mime_type' => 'image/png']);
        $this->withToken($firstToken)->postJson('/api/seller/media/link', ['url' => 'https://example.test/reused.png', 'file_name' => 'review-only.png'])
            ->assertCreated()->assertJsonPath('file_name', 'review-only.png');
    }

    public function test_admin_trash_clears_references_and_can_restore_or_purge_an_r2_file(): void
    {
        config()->set('filesystems.disks.r2.url', 'https://media.example.test');
        Storage::fake('r2');
        $admin = Admin::create(['name' => 'Media admin', 'email' => 'trash-media@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $token = $admin->createToken('test', ['admin'])->plainTextToken;
        $url = 'https://media.example.test/media/2026/11/trash-me.png';
        Storage::disk('r2')->put('media/2026/11/trash-me.png', 'image');
        $asset = MediaAsset::create(['owner_type' => 'admin', 'owner_id' => $admin->id, 'source' => 'upload', 'url' => $url, 'file_name' => 'trash-me.png', 'mime_type' => 'image/png']);
        $asset->storeLibraries()->create(['seller_id' => null, 'library_key' => 'house']);
        $category = ProductCategory::create(['name' => 'Trash category', 'slug' => 'trash-category']);
        $productId = DB::table('products')->insertGetId(['category_id' => $category->id, 'name' => 'Trash product', 'slug' => 'trash-product', 'description' => '<p><img src="'.$url.'"></p>', 'thumbnail' => $url, 'gallery' => json_encode([$url]), 'videos' => json_encode([$url]), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('homepage_clients')->insert(['name' => 'Trash client', 'logo' => $url, 'logo_dark' => $url, 'hidden' => false, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('site_settings')->insert(['key' => 'payment_accounts', 'payload' => json_encode([['id' => 'bangla_qr', 'qrImage' => $url]]), 'created_at' => now(), 'updated_at' => now()]);

        $this->withToken($token)->postJson("/api/admin/media/{$asset->id}/delete")
            ->assertOk()->assertJsonPath('message', 'Moved to trash. It will be permanently deleted in 30 days.');
        $this->withToken($token)->getJson('/api/admin/media')->assertOk()->assertJsonPath('total', 0);
        $this->withToken($token)->getJson('/api/admin/media/trash')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $asset->id)
            ->assertJsonPath('data.0.deleted_by_name', 'Media admin');
        $this->assertDatabaseHas('products', ['id' => $productId, 'thumbnail' => null, 'gallery' => json_encode([]), 'videos' => json_encode([])]);
        $this->assertDatabaseHas('homepage_clients', ['name' => 'Trash client', 'logo' => null, 'logo_dark' => null]);
        $this->assertStringNotContainsString($url, (string) DB::table('products')->where('id', $productId)->value('description'));
        $this->assertSame([['id' => 'bangla_qr', 'qrImage' => null]], json_decode((string) DB::table('site_settings')->where('key', 'payment_accounts')->value('payload'), true));
        Storage::disk('r2')->assertExists('media/2026/11/trash-me.png');

        $this->withToken($token)->postJson("/api/admin/media/{$asset->id}/restore")->assertOk()->assertJsonPath('id', $asset->id);
        $this->assertDatabaseHas('media_assets', ['id' => $asset->id, 'deleted_at' => null]);
        $this->withToken($token)->postJson("/api/admin/media/{$asset->id}/delete")->assertOk();
        $this->withToken($token)->postJson("/api/admin/media/{$asset->id}/purge")->assertOk();
        Storage::disk('r2')->assertMissing('media/2026/11/trash-me.png');
        $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
    }
}
