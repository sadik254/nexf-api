<?php

namespace Tests\Feature;

use App\Models\ProductCategory;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_storefront_returns_featured_stores_and_category_presentation_fields(): void
    {
        $category = ProductCategory::create([
            'name' => 'Fashion', 'slug' => 'fashion', 'is_active' => true,
            'image' => 'https://ucarecdn.com/123e4567-e89b-12d3-a456-426614174000/-/preview/',
            'menu_heading' => 'Shop fashion',
            'promo_image' => 'https://ucarecdn.com/123e4567-e89b-12d3-a456-426614174001/-/preview/',
            'promo_href' => '/collections/fashion', 'promo_title' => 'New season', 'promo_caption' => 'Made for every day',
        ]);
        Seller::create([
            'seller_name' => 'Featured owner', 'email' => 'featured@example.test',
            'store_name' => 'Featured Store', 'store_slug' => 'featured-store',
            'status' => 'approved', 'is_active' => true, 'is_featured' => true, 'password' => 'password123',
        ]);

        $categoryRow = collect($this->getJson('/api/store/categories')->assertOk()->json())
            ->firstWhere('id', $category->id);
        $this->assertSame($category->image, $categoryRow['image']);
        $this->assertSame('https://ucarecdn.com/123e4567-e89b-12d3-a456-426614174000/', $categoryRow['original_image']);
        $this->assertSame('Shop fashion', $categoryRow['menu_heading']);
        $this->assertSame($category->promo_image, $categoryRow['promo_image']);
        $this->assertSame('https://ucarecdn.com/123e4567-e89b-12d3-a456-426614174001/', $categoryRow['original_promo_image']);
        $this->assertSame('/collections/fashion', $categoryRow['promo_href']);
        $this->assertSame('New season', $categoryRow['promo_title']);
        $this->assertSame('Made for every day', $categoryRow['promo_caption']);
        $this->getJson('/api/store/stores')->assertOk()
            ->assertJsonPath('1.slug', 'featured-store')
            ->assertJsonPath('1.is_featured', true);
    }
}
