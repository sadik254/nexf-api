<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ProductCategory;
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
}
