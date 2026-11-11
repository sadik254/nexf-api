<?php

namespace Tests\Feature;

use App\Models\DeliveryGroup;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Seller;
use App\Models\SizeChart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SizeChartDeliveryGroupAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_sellers_only_see_own_and_shared_delivery_group_charts(): void
    {
        $first = $this->seller('first');
        $second = $this->seller('second');
        $outside = $this->seller('outside');
        DeliveryGroup::create([
            'name' => 'Shared stores',
            'seller_ids' => [$first->id, $second->id],
            'includes_house' => false,
            'ships_from_seller_id' => $first->id,
        ]);

        $own = SizeChart::create(['seller_id' => $first->id, 'name' => 'First chart', 'url' => 'https://example.test/first.png']);
        $shared = SizeChart::create(['seller_id' => $second->id, 'name' => 'Second chart', 'url' => 'https://example.test/second.png']);
        $house = SizeChart::create(['seller_id' => null, 'name' => 'House chart', 'url' => 'https://example.test/house.png']);
        $other = SizeChart::create(['seller_id' => $outside->id, 'name' => 'Outside chart', 'url' => 'https://example.test/outside.png']);

        $token = $first->createToken('test', ['seller'])->plainTextToken;
        $this->withToken($token)->getJson('/api/seller/size-charts')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment(['id' => $own->id])
            ->assertJsonFragment(['id' => $shared->id])
            ->assertJsonMissing(['id' => $house->id])
            ->assertJsonMissing(['id' => $other->id]);

        $category = ProductCategory::firstOrCreate(['slug' => 'shirts'], ['name' => 'Shirts']);
        $product = Product::create([
            'seller_id' => $first->id,
            'category_id' => $category->id,
            'name' => 'Shared size shirt',
            'slug' => 'shared-size-shirt',
            'product_type' => 'simple',
            'status' => 'draft',
        ]);

        $this->withToken($token)->postJson("/api/seller/products/{$product->id}", ['size_chart_id' => $shared->id])
            ->assertOk()
            ->assertJsonPath('product.size_chart_id', $shared->id);
        $this->withToken($token)->postJson("/api/seller/products/{$product->id}", ['size_chart_id' => $other->id])
            ->assertForbidden();
        $this->withToken($token)->postJson("/api/seller/products/{$product->id}", ['size_chart_id' => $house->id])
            ->assertForbidden();

        DeliveryGroup::query()->firstOrFail()->update(['includes_house' => true]);

        $this->withToken($token)->getJson('/api/seller/size-charts')
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonFragment(['id' => $house->id]);
        $this->withToken($token)->postJson("/api/seller/products/{$product->id}", ['size_chart_id' => $house->id])
            ->assertOk()
            ->assertJsonPath('product.size_chart_id', $house->id);
    }

    private function seller(string $key): Seller
    {
        return Seller::create([
            'seller_name' => ucfirst($key),
            'email' => "{$key}@size-chart.test",
            'store_name' => ucfirst($key).' Store',
            'store_slug' => "{$key}-store",
            'kyc_type' => 'nid',
            'kyc_number' => str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'kyc_document_url' => 'https://example.test/id.png',
            'product_category' => 'Clothing',
            'status' => 'approved',
            'is_active' => true,
            'password' => 'password123',
        ]);
    }
}
