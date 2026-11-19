<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Coupon;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponNullCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_blank_codes_keep_automatic_codes_and_validate_normal_coupon_updates(): void
    {
        $admin = Admin::create(['name' => 'Discount admin', 'email' => 'null-code-admin@example.test', 'password' => 'password123', 'role' => 'super_admin']);
        $adminToken = $admin->createToken('test', ['admin'])->plainTextToken;
        $automatic = Coupon::create(['code' => 'AUTO-KEEP-ADMIN', 'is_automatic' => true, 'discount_type' => 'fixed', 'discount_value' => 10]);
        $manual = Coupon::create(['code' => 'MANUAL-ADMIN', 'is_automatic' => false, 'discount_type' => 'fixed', 'discount_value' => 10]);

        $this->withToken($adminToken)->postJson("/api/admin/coupons/{$automatic->id}", ['code' => '', 'name' => 'Updated automatic'])
            ->assertOk()->assertJsonPath('coupon.code', 'AUTO-KEEP-ADMIN');
        $this->withToken($adminToken)->postJson("/api/admin/coupons/{$manual->id}", ['code' => ''])
            ->assertUnprocessable()->assertJsonPath('message', 'Coupon code is invalid.');
    }

    public function test_seller_blank_codes_follow_the_same_rules_and_public_validation_never_crashes(): void
    {
        $seller = Seller::create([
            'seller_name' => 'Coupon seller', 'email' => 'null-code-seller@example.test', 'store_name' => 'Coupon Store', 'store_slug' => 'null-code-store',
            'kyc_type' => 'nid', 'kyc_number' => 'null-code-kyc', 'kyc_document_url' => 'https://example.test/kyc', 'product_category' => 'General',
            'status' => 'approved', 'is_active' => true, 'password' => 'password123',
        ]);
        $token = $seller->createToken('test', ['seller:basic'])->plainTextToken;
        $automatic = Coupon::create(['seller_id' => $seller->id, 'code' => 'AUTO-KEEP-SELLER', 'is_automatic' => true, 'discount_type' => 'fixed', 'discount_value' => 10]);
        $manual = Coupon::create(['seller_id' => $seller->id, 'code' => 'MANUAL-SELLER', 'is_automatic' => false, 'discount_type' => 'fixed', 'discount_value' => 10]);

        $this->withToken($token)->postJson("/api/seller/coupons/{$automatic->id}", ['code' => '', 'name' => 'Updated automatic'])
            ->assertOk()->assertJsonPath('coupon.code', 'AUTO-KEEP-SELLER');
        $this->withToken($token)->postJson("/api/seller/coupons/{$manual->id}", ['code' => ''])
            ->assertUnprocessable()->assertJsonPath('message', 'Coupon code is invalid.');
        $this->postJson('/api/coupons/validate', ['code' => '', 'subtotal' => 1])->assertUnprocessable();
    }
}
