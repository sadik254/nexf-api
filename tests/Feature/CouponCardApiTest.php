<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Coupon;
use App\Models\HomepageOfferSet;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponCardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_coupon_sets_start_empty_and_accept_text_cards(): void
    {
        $admin = Admin::create(['name' => 'Root', 'email' => 'root@example.test', 'password' => 'password123', 'role' => 'super_admin']);
        $token = $admin->createToken('test', ['admin'])->plainTextToken;

        $set = $this->withToken($token)->postJson('/api/admin/homepage/offer-sets', [
            'row_type' => 'coupons', 'name' => 'Eid savings',
        ])->assertCreated()->assertJsonCount(0, 'blocks')->json();

        $this->withToken($token)->postJson("/api/admin/homepage/offer-sets/{$set['id']}/blocks", [
            'title' => 'Save ৳100', 'body' => 'On your first order', 'cta' => 'EID100', 'hidden' => false,
        ])->assertCreated()->assertJsonPath('block.image', '')->assertJsonPath('block.href', '');

        $this->getJson('/api/homepage/offer-blocks')->assertOk()
            ->assertJsonFragment(['row_type' => 'coupons', 'cta' => 'EID100']);
    }

    public function test_coupon_layout_requires_a_matching_coupon_set(): void
    {
        $admin = Admin::create(['name' => 'Root', 'email' => 'layout@example.test', 'password' => 'password123', 'role' => 'super_admin']);
        $token = $admin->createToken('test', ['admin'])->plainTextToken;
        $wrong = HomepageOfferSet::create(['row_type' => 'wide', 'name' => 'Wide']);

        $this->withToken($token)->postJson('/api/admin/homepage/layout', ['sections' => [[
            'id' => 'coupon-row', 'type' => 'coupons', 'enabled' => true, 'offer_set_id' => $wrong->id,
        ]]])->assertUnprocessable()->assertJsonValidationErrors('sections.0.offer_set_id');
    }

    public function test_seller_coupon_cards_are_text_only_and_publicly_hide_off_or_expired_cards(): void
    {
        $seller = Seller::create([
            'seller_name' => 'Seller', 'email' => 'seller@example.test', 'store_name' => 'Coupon Store',
            'store_slug' => 'coupon-store', 'status' => 'approved', 'is_active' => true, 'password' => 'password123',
        ]);
        $coupon = Coupon::create(['seller_id' => $seller->id, 'code' => 'SAVE10', 'discount_kind' => 'order', 'discount_type' => 'percentage', 'discount_value' => 10]);
        $sellerToken = $seller->createToken('test', ['seller'])->plainTextToken;

        $row = $this->withToken($sellerToken)->postJson('/api/seller/promotions', [
            'title' => 'Coupon cards', 'layout' => 'coupons', 'blocks' => [
                ['title' => 'Visible', 'body' => 'Terms', 'cta' => 'SAVE10', 'coupon_id' => $coupon->id, 'hidden' => false],
                ['title' => 'Hidden', 'body' => 'Terms', 'cta' => 'SAVE10', 'coupon_id' => $coupon->id, 'hidden' => true],
                ['title' => 'Expired', 'body' => 'Terms', 'cta' => 'SAVE10', 'coupon_id' => $coupon->id, 'expires_at' => now()->subDay()->toDateString()],
            ],
        ])->assertCreated()->json();

        $this->withToken($sellerToken)->postJson("/api/seller/promotions/{$row['id']}/submit")
            ->assertOk()->assertJsonPath('status', 'pending');

        $admin = Admin::create(['name' => 'Root', 'email' => 'offers@example.test', 'password' => 'password123', 'role' => 'super_admin']);
        $adminToken = $admin->createToken('test', ['admin'])->plainTextToken;
        $this->withToken($adminToken)->postJson("/api/admin/seller-promotions/{$row['id']}", ['status' => 'approved'])
            ->assertOk()->assertJsonPath('status', 'approved');

        $this->getJson('/api/promotions')->assertOk()->assertJsonCount(1)
            ->assertJsonCount(1, '0.blocks')->assertJsonPath('0.blocks.0.title', 'Visible');
    }
}
