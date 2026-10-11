<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Admin;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerCouponTest extends TestCase
{
    use RefreshDatabase;

    public function test_sellers_manage_only_their_discounts_and_products(): void
    {
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']);
        $makeSeller = fn (string $key) => Seller::create([
            'seller_name' => "Seller {$key}", 'email' => "seller-{$key}@coupon.test", 'store_name' => "Store {$key}",
            'store_slug' => "store-{$key}", 'kyc_type' => 'nid', 'kyc_number' => "coupon-{$key}",
            'kyc_document_url' => 'https://example.test/kyc', 'product_category' => 'Clothing', 'status' => 'approved',
            'is_active' => true, 'password' => 'password123',
        ]);
        $seller = $makeSeller('a');
        $other = $makeSeller('b');
        $ownProduct = Product::create(['seller_id' => $seller->id, 'category_id' => $category->id, 'name' => 'Own item', 'slug' => 'own-item', 'product_type' => 'simple', 'status' => 'active']);
        $otherProduct = Product::create(['seller_id' => $other->id, 'category_id' => $category->id, 'name' => 'Other item', 'slug' => 'other-item', 'product_type' => 'simple', 'status' => 'active']);
        $token = $seller->createToken('test', ['seller:basic'])->plainTextToken;
        $input = ['code' => 'STORE10', 'discount_kind' => 'products', 'discount_type' => 'percentage', 'discount_value' => 10, 'eligible_product_ids' => [$ownProduct->id]];

        $coupon = $this->withToken($token)->postJson('/api/seller/coupons', $input)->assertCreated()
            ->assertJsonPath('coupon.seller_id', $seller->id)->assertJsonPath('coupon.created_by_seller_id', $seller->id)->json('coupon');
        $this->withToken($token)->getJson('/api/seller/coupons')->assertOk()->assertJsonPath('total', 1);
        $this->withToken($token)->postJson('/api/seller/coupons', ['code' => 'FOREIGN', 'discount_kind' => 'products', 'discount_type' => 'percentage', 'discount_value' => 10, 'eligible_product_ids' => [$otherProduct->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('eligible_product_ids');

        $otherToken = $other->createToken('test', ['seller:basic'])->plainTextToken;
        $otherCoupon = $this->withToken($otherToken)->postJson('/api/seller/coupons', [
            'code' => 'OTHER10', 'discount_kind' => 'order', 'discount_type' => 'percentage', 'discount_value' => 10,
        ])->assertCreated()->json('coupon');
        $this->withToken($token)->getJson('/api/seller/coupons')->assertOk()->assertJsonPath('total', 1);
        $this->withToken($token)->postJson("/api/seller/coupons/{$otherCoupon['id']}", ['name' => 'Takeover'])->assertNotFound();
        $this->assertDatabaseHas('coupons', ['id' => $coupon['id'], 'name' => null]);
    }

    public function test_regular_admin_can_manage_reference_discount_page(): void
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => 'coupon-admin@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $login = $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'password123'])->assertOk();
        $token = $login->json('token');

        $this->withToken($token)->postJson('/api/admin/coupons', [
            'code' => 'ADMIN10', 'discount_kind' => 'order', 'discount_type' => 'percentage', 'discount_value' => 10,
        ])->assertCreated();
    }
}
