<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Coupon;
use App\Models\Seller;
use App\Models\SellerPromotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerPromotionTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_promotions_are_scoped_and_public_results_are_active_and_unexpired(): void
    {
        $one = $this->seller('one-promo@example.test', 'one-store');
        $two = $this->seller('two-promo@example.test', 'two-store');
        $oneToken = $one->createToken('test', ['seller:basic'])->plainTextToken;
        $twoToken = $two->createToken('test', ['seller:basic'])->plainTextToken;
        Coupon::create(['seller_id' => $one->id, 'code' => 'ONE15', 'discount_type' => 'percentage', 'discount_value' => 15, 'is_active' => true]);

        $id = $this->withToken($oneToken)->postJson('/api/seller/promotions', [
            'title' => 'Spring edit', 'discount_label' => '15% OFF', 'body' => 'Selected pieces.',
            'code' => 'ONE15', 'expires_at' => now()->addWeek()->toDateString(), 'theme' => 'rose',
        ])->assertCreated()->assertJsonPath('seller_id', $one->id)->assertJsonPath('pinned', false)->json('id');
        $this->withToken($oneToken)->postJson("/api/seller/promotions/{$id}", ['pinned' => true])->assertOk()->assertJsonPath('pinned', true);

        $this->withToken($twoToken)->getJson('/api/seller/promotions')->assertOk()->assertJsonCount(0);
        $this->withToken($twoToken)->postJson("/api/seller/promotions/{$id}", ['title' => 'Hijack'])->assertNotFound();
        $this->withToken($twoToken)->postJson("/api/seller/promotions/{$id}/delete")->assertNotFound();

        $admin = Admin::create(['name' => 'Admin', 'email' => 'promo-admin@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $adminToken = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $this->withToken($adminToken)->postJson("/api/admin/seller-promotions/{$id}", ['pinned' => true])->assertOk()->assertJsonPath('pinned', true);
        $this->withToken($adminToken)->postJson("/api/admin/seller-promotions/{$id}", ['seller_id' => $two->id])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->getJson('/api/store/promotions')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $id)->assertJsonPath('0.seller.store_slug', 'one-store');
        SellerPromotion::whereKey($id)->update(['expires_at' => now()->subDay()->toDateString()]);
        $this->getJson('/api/store/promotions')->assertOk()->assertJsonCount(0);
    }

    public function test_promotion_code_must_be_a_live_coupon_for_the_same_seller(): void
    {
        $one = $this->seller('code-one@example.test', 'code-one');
        $two = $this->seller('code-two@example.test', 'code-two');
        Coupon::create(['seller_id' => $one->id, 'code' => 'ONE15', 'discount_type' => 'percentage', 'discount_value' => 15, 'is_active' => true]);
        $token = $one->createToken('test', ['seller:basic'])->plainTextToken;

        $this->withToken($token)->postJson('/api/seller/promotions', ['title' => 'Bad code', 'discount_label' => '15% OFF', 'code' => 'NOTREAL'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->withToken($token)->postJson('/api/seller/promotions', ['title' => 'Other store code', 'discount_label' => '15% OFF', 'code' => 'ONE15'])->assertCreated();
        $otherToken = $two->createToken('test', ['seller:basic'])->plainTextToken;
        $this->withToken($otherToken)->postJson('/api/seller/promotions', ['title' => 'Cross store', 'discount_label' => '15% OFF', 'code' => 'ONE15'])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    private function seller(string $email, string $slug): Seller
    {
        return Seller::create([
            'seller_name' => $slug, 'email' => $email, 'store_name' => $slug, 'store_slug' => $slug,
            'kyc_type' => 'nid', 'kyc_number' => '123456', 'kyc_document_url' => 'https://example.test/id',
            'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123',
        ]);
    }
}
