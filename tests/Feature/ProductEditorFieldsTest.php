<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Admin;
use App\Models\ProductCategory;
use App\Models\ProductLot;
use App\Models\Seller;
use App\Models\MediaAsset;
use App\Services\ProductHtmlSanitizer;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductEditorFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_editor_fields_are_cast_and_persisted(): void
    {
        $product = new Product();
        $product->fill([
            'specification_tables' => [['title' => 'Material', 'rows' => [['label' => 'Fabric', 'value' => 'Cotton']]]],
            'videos' => ['https://example.com/demo.mp4'],
            'weight_kg' => 0.75,
        ]);

        $this->assertSame('Cotton', $product->specification_tables[0]['rows'][0]['value']);
        $this->assertSame(['https://example.com/demo.mp4'], $product->videos);
        $this->assertEquals(0.75, $product->weight_kg);
        $this->assertTrue(\Schema::hasColumns('products', ['specification_tables', 'videos', 'weight_kg']));
    }

    public function test_product_description_removes_unsafe_markup(): void
    {
        $html = app(ProductHtmlSanitizer::class)->clean('<p onclick="evil()">Hello<script>alert(1)</script><a href="javascript:alert(1)">bad</a><a href="https://example.com">good</a></p>');

        $this->assertStringContainsString('Hello', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);

        $media = app(ProductHtmlSanitizer::class)->clean('<table><tr><th colspan="2" onclick="evil()">Size</th></tr></table><img src="https://example.com/coat.jpg" alt="Coat" onerror="evil()"><video src="https://example.com/fit.mp4" controls autoplay></video><iframe src="https://www.youtube-nocookie.com/embed/abc123XYZ" title="Video"></iframe><iframe src="https://example.com/evil"></iframe><img src="javascript:alert(1)">');
        $this->assertStringContainsString('colspan="2"', $media);
        $this->assertStringContainsString('src="https://example.com/coat.jpg"', $media);
        $this->assertStringContainsString('src="https://example.com/fit.mp4"', $media);
        $this->assertStringContainsString('src="https://www.youtube-nocookie.com/embed/abc123XYZ"', $media);
        $this->assertStringNotContainsString('https://example.com/evil', $media);
        $this->assertStringNotContainsString('onerror', $media);
        $this->assertStringNotContainsString('autoplay', $media);
        $this->assertStringNotContainsString('javascript:', $media);
    }

    public function test_super_admin_seller_product_editor_saves_media_videos_and_variations(): void
    {
        $seller = Seller::create(['seller_name' => 'Seller', 'email' => 'editor-seller@example.test', 'store_name' => 'Editor Store', 'store_slug' => 'editor-store', 'kyc_type' => 'nid', 'kyc_number' => '99', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $admin = Admin::create(['name' => 'Super', 'email' => 'seller-editor-admin@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $category = ProductCategory::create(['name' => 'Coats', 'slug' => 'editor-coats']);
        $product = Product::create(['seller_id' => $seller->id, 'category_id' => $category->id, 'name' => 'Coat', 'slug' => 'editor-coat', 'product_type' => 'variable', 'status' => 'draft']);
        $asset = MediaAsset::create(['owner_type' => 'admin', 'owner_id' => $admin->id, 'source' => 'url', 'url' => 'https://example.test/coat.jpg', 'file_name' => 'coat.jpg', 'mime_type' => 'image/jpeg']);
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;

        $assignedId = $this->withToken($token)->postJson('/api/admin/products', ['seller_id' => $seller->id, 'category_id' => $category->id, 'name' => 'Second coat', 'product_type' => 'simple'])
            ->assertCreated()->assertJsonPath('product.seller_id', $seller->id)->json('product.id');
        $this->assertNotNull($assignedId);
        $basic = Admin::create(['name' => 'Basic', 'email' => 'basic-editor@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $this->withToken($basic->createToken('test', ['admin:basic'])->plainTextToken)->postJson('/api/admin/products', ['seller_id' => $seller->id, 'category_id' => $category->id, 'name' => 'Forbidden coat', 'product_type' => 'simple'])->assertForbidden();

        $this->withToken($token)->postJson("/api/admin/sellers/{$seller->id}/products/{$product->id}", [
            'thumbnail_media_id' => $asset->id,
            'videos' => ['https://example.test/coat.mp4'],
            'description' => '<p>Coat</p><img src="https://example.test/coat.jpg" alt="Coat">',
            'compare_at_price' => 700,
            'option_groups' => [['key' => 'Color', 'label' => 'Color', 'display_type' => 'swatch', 'values' => [['value' => 'Pink', 'label' => 'Pink', 'swatch' => '#f5a5c8']]], ['key' => 'Pattern', 'label' => 'Pattern', 'display_type' => 'image', 'values' => [['value' => 'Floral', 'label' => 'Floral', 'image_url' => 'https://example.test/floral.jpg']]]],
        ])->assertOk()->assertJsonPath('product.thumbnail', $asset->url)
            ->assertJsonPath('product.videos.0', 'https://example.test/coat.mp4')
            ->assertJsonPath('product.option_groups.0.values.0.swatch', '#f5a5c8')
            ->assertJsonPath('product.option_groups.1.values.0.image_url', 'https://example.test/floral.jpg')
            ->assertJsonPath('product.compare_at_price', '700.00');
        $this->assertStringContainsString('<img src="https://example.test/coat.jpg"', $product->fresh()->description);
        $this->withToken($token)->postJson("/api/admin/products/{$product->id}/variations", ['attributes' => ['Color' => 'Pink', 'Size' => 'M'], 'default_selling_price' => 500])
            ->assertCreated()->assertJsonPath('variation.attributes.Color', 'Pink');
        $this->withToken($token)->postJson("/api/admin/sellers/{$seller->id}/products/{$product->id}", ['product_type' => 'simple'])
            ->assertUnprocessable();
        $this->withToken($token)->postJson("/api/admin/sellers/{$seller->id}/products/{$product->id}", ['clear_option_groups' => true])
            ->assertOk()->assertJsonPath('product.option_groups', []);
        $this->withToken($token)->postJson("/api/admin/sellers/{$seller->id}/products/{$product->id}", [
            'option_groups' => [['key' => 'Size', 'label' => 'Size', 'display_type' => 'button', 'values' => [['value' => 'M', 'label' => 'M'], ['value' => 'M', 'label' => 'M']]]],
        ])->assertUnprocessable();
    }

    public function test_editor_reports_independent_product_prices_and_inventory_cost(): void
    {
        $admin = Admin::create(['name' => 'Owner', 'email' => 'pricing-owner@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $category = ProductCategory::create(['name' => 'Coats', 'slug' => 'pricing-coats']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Pink coat', 'slug' => 'pricing-pink-coat', 'product_type' => 'simple', 'status' => 'active', 'default_selling_price' => 360, 'compare_at_price' => 500]);
        ProductLot::create(['product_id' => $product->id, 'lot_number' => 'SALE-1', 'buying_price' => 200, 'selling_price' => 360, 'quantity' => 5, 'quantity_remaining' => 5]);

        $this->withToken($token)->getJson("/api/admin/products/{$product->id}")
            ->assertOk()->assertJsonPath('default_selling_price', 360)->assertJsonPath('compare_at_price', '500.00')->assertJsonPath('current_buying_price', 200);
    }

    public function test_storefront_and_checkout_use_product_price_instead_of_lot_selling_snapshot(): void
    {
        $category = ProductCategory::create(['name' => 'Jackets', 'slug' => 'pricing-jackets']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Jacket', 'slug' => 'pricing-jacket', 'product_type' => 'simple', 'status' => 'active', 'default_selling_price' => 420, 'compare_at_price' => 600]);
        ProductLot::create(['product_id' => $product->id, 'lot_number' => 'OLD-PRICE', 'buying_price' => 200, 'selling_price' => 360, 'quantity' => 5, 'quantity_remaining' => 5]);

        $this->getJson('/api/store/products/pricing-jacket')->assertOk()
            ->assertJsonPath('current_selling_price', '420')->assertJsonPath('compare_at_price', '600.00');
        $quote = app(InventoryService::class)->previewProduct($product, 2);
        $this->assertSame(840.0, $quote['subtotal']);
        $this->assertSame(400.0, $quote['cost']);
        $sale = app(InventoryService::class)->consumeProduct($product, 2, null);
        $this->assertSame(840.0, $sale['totals']['revenue']);
        $this->assertSame(400.0, $sale['totals']['cost']);
        $this->assertSame('420.00', $sale['allocations'][0]['unit_selling_price']);
    }

    public function test_legacy_price_backfill_prefers_the_available_lot(): void
    {
        $category = ProductCategory::create(['name' => 'Shirts', 'slug' => 'pricing-shirts']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Shirt', 'slug' => 'pricing-shirt', 'product_type' => 'simple', 'status' => 'active']);
        ProductLot::create(['product_id' => $product->id, 'lot_number' => 'OLD', 'buying_price' => 50, 'selling_price' => 100, 'quantity' => 2, 'quantity_remaining' => 0]);
        ProductLot::create(['product_id' => $product->id, 'lot_number' => 'CURRENT', 'buying_price' => 60, 'selling_price' => 130, 'quantity' => 2, 'quantity_remaining' => 2]);

        $migration = require database_path('migrations/2026_10_06_000011_backfill_catalogue_selling_prices.php');
        $migration->up();

        $this->assertEquals(130, $product->fresh()->default_selling_price);
    }
}
