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
            'shipping_options' => [['label' => 'Inside Dhaka', 'price' => 60]],
            'audience' => 'women',
        ]);

        $this->assertSame('Cotton', $product->specification_tables[0]['rows'][0]['value']);
        $this->assertSame(['https://example.com/demo.mp4'], $product->videos);
        $this->assertEquals(0.75, $product->weight_kg);
        $this->assertSame('Inside Dhaka', $product->shipping_options[0]['label']);
        $this->assertSame('women', $product->audience);
        $this->assertTrue(\Schema::hasColumns('products', ['specification_tables', 'videos', 'weight_kg', 'shipping_options', 'audience']));
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

    public function test_public_products_return_explicit_audience_and_size_kind(): void
    {
        $category = ProductCategory::create(['name' => 'Filter women', 'slug' => 'filter-women']);
        Product::create([
            'category_id' => $category->id, 'name' => 'Filter dress', 'slug' => 'filter-dress', 'product_type' => 'variable', 'status' => 'active', 'audience' => 'women',
            'option_groups' => [['key' => 'size', 'label' => 'Size', 'display_type' => 'button', 'size_kind' => 'clothing', 'values' => [['value' => 'M', 'label' => 'M']]]],
        ]);

        $this->getJson('/api/store/products/filter-dress')->assertOk()
            ->assertJsonPath('audience', 'women')
            ->assertJsonPath('option_groups.0.size_kind', 'clothing');
    }

    public function test_a_third_duplicate_category_or_product_name_gets_the_next_slug(): void
    {
        $admin = Admin::create(['name' => 'Slug editor', 'email' => 'slug-editor@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $category = ProductCategory::create(['name' => 'Catalogue', 'slug' => 'catalogue']);
        ProductCategory::create(['name' => 'Jacket', 'slug' => 'jacket']);
        ProductCategory::create(['name' => 'Jacket duplicate', 'slug' => 'jacket-1']);

        $this->withToken($token)->postJson('/api/admin/product-categories/taxonomy', [
            'name' => 'Jacket',
            'subcategories' => [],
        ])->assertOk()->assertJsonPath('category.slug', 'jacket-2');

        Product::create(['category_id' => $category->id, 'name' => 'Coat', 'slug' => 'coat', 'product_type' => 'simple', 'status' => 'draft']);
        Product::create(['category_id' => $category->id, 'name' => 'Coat two', 'slug' => 'coat-1', 'product_type' => 'simple', 'status' => 'draft']);

        $this->withToken($token)->postJson('/api/admin/products', [
            'category_id' => $category->id,
            'name' => 'Coat',
            'product_type' => 'simple',
        ])->assertCreated()->assertJsonPath('product.slug', 'coat-2');
    }

    public function test_products_can_be_placed_in_another_subcategory_with_its_own_photos(): void
    {
        $admin = Admin::create(['name' => 'Placement editor', 'email' => 'placement-editor@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $men = ProductCategory::create(['name' => 'Men', 'slug' => 'placement-men']);
        $menHoodies = ProductCategory::create(['parent_id' => $men->id, 'name' => 'Hoodies', 'slug' => 'placement-men-hoodies']);
        $women = ProductCategory::create(['name' => 'Women', 'slug' => 'placement-women']);
        $womenHoodies = ProductCategory::create(['parent_id' => $women->id, 'name' => 'Hoodies', 'slug' => 'placement-women-hoodies']);
        $kids = ProductCategory::create(['name' => 'Kids', 'slug' => 'placement-kids']);
        $kidsHoodies = ProductCategory::create(['parent_id' => $kids->id, 'name' => 'Hoodies', 'slug' => 'placement-kids-hoodies']);
        $product = Product::create([
            'category_id' => $menHoodies->id,
            'name' => 'Shared hoodie',
            'slug' => 'shared-hoodie',
            'product_type' => 'simple',
            'status' => 'active',
            'thumbnail' => 'https://example.test/hoodie-cover.jpg',
            'gallery' => ['https://example.test/hoodie-men.jpg', 'https://example.test/hoodie-women.jpg'],
            'videos' => ['https://example.test/hoodie-men.mp4', 'https://example.test/hoodie-women.mp4'],
        ]);

        $this->withToken($token)->postJson("/api/admin/products/{$product->id}", [
            'main_photos' => [0, 1],
            'main_videos' => [0],
            'category_placements' => [[
                'category_id' => $womenHoodies->id,
                'photos' => [2],
                'videos' => [1],
            ], [
                'category_id' => $kidsHoodies->id,
                'photos' => [2],
                'videos' => [1],
            ]],
        ])->assertOk()
            ->assertJsonPath('product.main_photos.1', 1)
            ->assertJsonPath('product.main_videos.0', 0)
            ->assertJsonPath('product.category_placements.0.category.slug', 'placement-women-hoodies')
            ->assertJsonPath('product.category_placements.0.photos.0', 2);


        $this->getJson('/api/store/categories/placement-women-hoodies/products')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'shared-hoodie')
            ->assertJsonPath('data.0.category_placements.0.photos.0', 2)
            ->assertJsonPath('data.0.category_placements.0.videos.0', 1)
            ->assertJsonPath('data.0.main_photos.0', 0);

        // Older clients may only send the changed video list. Selections
        // follow their URLs instead of accidentally selecting a new index.
        $this->withToken($token)->postJson("/api/admin/products/{$product->id}", [
            'videos' => ['https://example.test/hoodie-women.mp4'],
        ])->assertOk()
            ->assertJsonPath('product.main_videos', [])
            ->assertJsonPath('product.category_placements.0.videos.0', 0);

        $this->withToken($token)->postJson("/api/admin/products/{$product->id}", [
            'clear_category_placements' => true,
            'clear_main_photos' => true,
            'clear_main_videos' => true,
        ])->assertOk()
            ->assertJsonPath('product.main_photos', [])
            ->assertJsonPath('product.main_videos', [])
            ->assertJsonPath('product.category_placements', []);
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
        $houseProduct = Product::create(['category_id' => $category->id, 'name' => 'House coat', 'slug' => 'house-coat', 'product_type' => 'simple', 'status' => 'draft']);
        $this->withToken($token)->postJson("/api/admin/products/{$houseProduct->id}", ['seller_id' => $seller->id])->assertOk()->assertJsonPath('product.seller_id', $seller->id);
        $this->withToken($basic->createToken('test', ['admin:basic'])->plainTextToken)->postJson("/api/admin/products/{$houseProduct->id}", ['seller_id' => null])->assertForbidden();

        $this->withToken($token)->postJson("/api/admin/sellers/{$seller->id}/products/{$product->id}", [
            'thumbnail_media_id' => $asset->id,
            'videos' => ['https://example.test/coat.mp4'],
            'description' => '<p>Coat</p><img src="https://example.test/coat.jpg" alt="Coat">',
            'compare_at_price' => 700,
            'discount_kind' => 'flat',
            'discount_value' => 100,
            'shipping_options' => [['label' => 'Inside Dhaka', 'price' => 60], ['label' => 'Outside Dhaka', 'price' => 120]],
            'audience' => 'women',
            'option_groups' => [['key' => 'Color', 'label' => 'Color', 'display_type' => 'swatch', 'values' => [['value' => 'Pink', 'label' => 'Pink', 'swatch' => '#f5a5c8']]], ['key' => 'Size', 'label' => 'Size', 'display_type' => 'button', 'size_kind' => 'clothing', 'values' => [['value' => 'M', 'label' => 'M']]]],
        ])->assertOk()->assertJsonPath('product.thumbnail', $asset->url)
            ->assertJsonPath('product.videos.0', 'https://example.test/coat.mp4')
            ->assertJsonPath('product.option_groups.0.values.0.swatch', '#f5a5c8')
            ->assertJsonPath('product.option_groups.1.size_kind', 'clothing')
            ->assertJsonPath('product.audience', 'women')
            ->assertJsonPath('product.compare_at_price', '700.00')
            ->assertJsonPath('product.discount_kind', 'flat')
            ->assertJsonPath('product.discount_value', '100.00')
            ->assertJsonPath('product.shipping_options.1.label', 'Outside Dhaka');
        $this->assertStringContainsString('<img src="https://example.test/coat.jpg"', $product->fresh()->description);
        $this->withToken($token)->postJson("/api/admin/sellers/{$seller->id}/products/{$product->id}", ['clear_shipping_options' => true])->assertOk()->assertJsonPath('product.shipping_options', []);
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
            ->assertOk()->assertJsonPath('default_selling_price', 360)->assertJsonPath('compare_at_price', '500.00')->assertJsonPath('current_buying_price', 200)->assertJsonPath('available_quantity', 5);
    }

    public function test_product_editor_can_save_product_level_buying_cost(): void
    {
        $admin = Admin::create(['name' => 'Cost Editor', 'email' => 'cost-editor@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $category = ProductCategory::create(['name' => 'Cost Items', 'slug' => 'cost-items']);
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $productId = $this->withToken($token)->postJson('/api/admin/products', [
            'category_id' => $category->id,
            'name' => 'Costed item',
            'product_type' => 'simple',
            'default_selling_price' => 500,
            'default_buying_price' => 240,
        ])->assertCreated()->assertJsonPath('product.default_buying_price', 240)->json('product.id');

        $this->withToken($token)->postJson("/api/admin/products/{$productId}", ['default_buying_price' => 260])
            ->assertOk()->assertJsonPath('product.default_buying_price', 260);
        $this->withToken($token)->postJson("/api/admin/products/{$productId}", ['default_buying_price' => -1])
            ->assertUnprocessable();
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
