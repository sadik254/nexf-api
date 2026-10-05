<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageCategoryCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_cards_are_ordered_visible_and_managed_only_by_super_admin(): void
    {
        $category = ProductCategory::create(['name' => 'Coats', 'slug' => 'coats', 'is_active' => true]);
        $admin = Admin::create(['name' => 'Admin', 'email' => 'admin-card@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $super = Admin::create(['name' => 'Super', 'email' => 'super-card@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $cards = [
            ['category_id' => $category->id, 'name' => 'Coats', 'caption' => 'Warm layers', 'href' => '/shop?category=coats', 'image' => '/assets/category/men.png', 'mobile_image' => null, 'cta' => 'Shop Now', 'theme' => 'blue', 'hidden' => false],
            ['category_id' => null, 'name' => 'Hidden', 'caption' => null, 'href' => '/shop', 'image' => '/assets/category/women.png', 'mobile_image' => null, 'cta' => null, 'theme' => 'pink', 'hidden' => true],
        ];
        $this->withToken($admin->createToken('test', ['admin:basic'])->plainTextToken)
            ->postJson('/api/admin/homepage/category-cards', ['cards' => $cards])->assertForbidden();
        $token = $super->createToken('test', ['admin:basic'])->plainTextToken;
        $this->withToken($token)->postJson('/api/admin/homepage/category-cards', ['cards' => $cards])
            ->assertOk()->assertJsonCount(2)->assertJsonPath('0.name', 'Coats');
        $this->getJson('/api/homepage/category-cards')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.category.slug', 'coats');
        $category->update(['is_active' => false]);
        $this->getJson('/api/homepage/category-cards')->assertOk()->assertJsonCount(0);
        $this->withToken($token)->postJson('/api/admin/homepage/category-cards', ['cards' => [[
            ...$cards[0], 'href' => 'javascript:alert(1)',
        ]]])->assertUnprocessable();
    }
}
