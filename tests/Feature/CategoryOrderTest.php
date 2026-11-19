<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ProductCategory;
use App\Models\Seller;
use App\Models\SiteSetting;
use App\Models\SizeChart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_top_level_categories_reorder_and_reject_invalid_lists(): void
    {
        $admin = Admin::create(['name' => 'Root', 'email' => 'category-order@example.test', 'password' => 'password123', 'role' => 'super_admin']);
        $token = $admin->createToken('test', ['admin'])->plainTextToken;
        $women = ProductCategory::create(['name' => 'Order Women', 'slug' => 'order-women']);
        $kids = ProductCategory::create(['name' => 'Order Kids', 'slug' => 'order-kids']);
        $toys = ProductCategory::create(['name' => 'Order Toys', 'slug' => 'order-toys']);
        $child = ProductCategory::create(['name' => 'Order Dolls', 'slug' => 'order-dolls', 'parent_id' => $kids->id]);

        $remaining = ProductCategory::query()->whereNull('parent_id')->whereNotIn('id', [$toys->id, $women->id, $kids->id])->pluck('id')->all();
        $ids = [$toys->id, $women->id, $kids->id, ...$remaining];
        $this->withToken($token)->postJson('/api/admin/product-categories/reorder', ['ids' => $ids])
            ->assertOk()->assertJsonPath('message', 'Category order saved.');
        $this->withToken($token)->getJson('/api/admin/product-categories?per_page=100')
            ->assertOk()->assertJsonPath('data.0.id', $toys->id)->assertJsonPath('data.1.id', $women->id);
        $this->getJson('/api/store/categories')->assertOk()->assertJsonPath('0.id', $toys->id);

        $this->withToken($token)->postJson('/api/admin/product-categories/reorder', ['ids' => [$toys->id, $toys->id, ...array_slice($ids, 2)]])
            ->assertUnprocessable()->assertJsonPath('errors.ids.0', 'Send every top-level category once. Reload and try again.');
        $this->withToken($token)->postJson('/api/admin/product-categories/reorder', ['ids' => [...$ids, $child->id]])
            ->assertUnprocessable()->assertJsonPath('errors.ids.0', 'Send every top-level category once. Reload and try again.');
    }

    public function test_taxonomy_order_and_new_categories_are_saved_last(): void
    {
        $admin = Admin::create(['name' => 'Root', 'email' => 'category-taxonomy-order@example.test', 'password' => 'password123', 'role' => 'super_admin']);
        $token = $admin->createToken('test', ['admin'])->plainTextToken;
        $parent = ProductCategory::create(['name' => 'Home', 'slug' => 'home']);
        $first = ProductCategory::create(['name' => 'First', 'slug' => 'first', 'parent_id' => $parent->id]);
        $second = ProductCategory::create(['name' => 'Second', 'slug' => 'second', 'parent_id' => $parent->id]);

        $this->withToken($token)->postJson("/api/admin/product-categories/{$parent->id}/taxonomy", [
            'name' => 'Home', 'subcategories' => [['id' => $second->id, 'name' => 'Second'], ['id' => $first->id, 'name' => 'First']],
        ])->assertOk()->assertJsonPath('category.children.0.id', $second->id);
        $new = $this->withToken($token)->postJson('/api/admin/product-category-options', ['name' => 'Third', 'parent_id' => $parent->id])
            ->assertCreated()->json();
        $this->assertSame(2, ProductCategory::findOrFail($new['id'])->sort_order);
    }

    public function test_subcategory_slugs_include_the_parent_and_rewrite_saved_slug_references(): void
    {
        $admin = Admin::create(['name' => 'Root', 'email' => 'category-slug@example.test', 'password' => 'password123', 'role' => 'super_admin']);
        $token = $admin->createToken('test', ['admin'])->plainTextToken;
        $parent = ProductCategory::create(['name' => 'Slug Men', 'slug' => 'slug-men']);
        $child = ProductCategory::create(['name' => 'Jacket', 'slug' => 'jacket-1', 'parent_id' => $parent->id]);
        SizeChart::create(['name' => 'Jacket chart', 'category_slug' => $parent->slug, 'subcategory_slug' => $child->slug]);
        SiteSetting::create(['key' => 'home_layout', 'payload' => [['id' => 'category-rail', 'type' => 'collection', 'enabled' => true, 'source' => 'category', 'sourceValue' => $child->slug]]]);
        $seller = Seller::create([
            'seller_name' => 'Layout seller', 'email' => 'category-layout@example.test', 'store_name' => 'Layout Store', 'store_slug' => 'category-layout-store',
            'kyc_type' => 'nid', 'kyc_number' => 'category-layout-kyc', 'kyc_document_url' => 'https://example.test/kyc', 'product_category' => 'General',
            'status' => 'approved', 'is_active' => true, 'password' => 'password123',
        ]);
        $seller->forceFill(['store_layout' => [['id' => 'category-rail', 'type' => 'collection', 'enabled' => true, 'source' => 'category', 'sourceValue' => $child->slug]]])->save();

        $this->withToken($token)->postJson("/api/admin/product-categories/{$parent->id}/taxonomy", [
            'name' => 'Slug Men Final',
            'subcategories' => [['id' => $child->id, 'name' => 'Jacket']],
        ])->assertOk()
            ->assertJsonPath('category.slug', 'slug-men-final')
            ->assertJsonPath('category.children.0.slug', 'slug-men-final-jacket');

        $this->assertDatabaseHas('size_charts', ['id' => SizeChart::firstOrFail()->id, 'category_slug' => 'slug-men-final', 'subcategory_slug' => 'slug-men-final-jacket']);
        $this->assertSame('slug-men-final-jacket', SiteSetting::findOrFail('home_layout')->payload[0]['sourceValue']);
        $this->assertSame('slug-men-final-jacket', $seller->fresh()->store_layout[0]['sourceValue']);

        $women = ProductCategory::create(['name' => 'Slug Women', 'slug' => 'slug-women']);
        $this->withToken($token)->postJson('/api/admin/product-categories', ['name' => 'Hoodie', 'parent_id' => $women->id])
            ->assertCreated()->assertJsonPath('category.slug', 'slug-women-hoodie');
    }
}
