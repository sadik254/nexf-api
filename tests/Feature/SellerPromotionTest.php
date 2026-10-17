<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Seller;
use App\Models\SellerPromotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerPromotionTest extends TestCase
{
    use RefreshDatabase;

    public function test_offer_rows_follow_draft_submission_and_review_lifecycle(): void
    {
        $seller = $this->seller('review@example.test', 'review-store');
        $sellerToken = $seller->createToken('test', ['seller:basic'])->plainTextToken;
        $admin = Admin::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $adminToken = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $blocks = [$this->block('One'), $this->block('Two')];

        $id = $this->withToken($sellerToken)->postJson('/api/seller/promotions', ['title' => 'Eid', 'layout' => 'two', 'blocks' => $blocks])
            ->assertCreated()->assertJsonPath('status', 'draft')->assertJsonPath('pinned', false)->json('id');
        $this->getJson('/api/store/promotions')->assertOk()->assertJsonCount(0);
        $this->withToken($sellerToken)->postJson("/api/seller/promotions/{$id}/submit")->assertOk()->assertJsonPath('status', 'pending');
        $this->withToken($adminToken)->postJson("/api/admin/seller-promotions/{$id}", ['status' => 'rejected'])
            ->assertUnprocessable()->assertJsonValidationErrors('review_note');
        $this->withToken($adminToken)->postJson("/api/admin/seller-promotions/{$id}", ['status' => 'rejected', 'review_note' => 'Use original photos.'])
            ->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('review_note', 'Use original photos.');
        $this->withToken($sellerToken)->postJson("/api/seller/promotions/{$id}/submit")->assertOk()->assertJsonPath('status', 'pending');
        $this->withToken($adminToken)->postJson("/api/admin/seller-promotions/{$id}", ['status' => 'approved', 'pinned' => true])
            ->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('pinned', true);
        $this->getJson('/api/store/promotions')->assertOk()->assertJsonCount(1)->assertJsonPath('0.layout', 'two')->assertJsonPath('0.seller.store_slug', 'review-store');
        $this->withToken($sellerToken)->postJson("/api/seller/promotions/{$id}", ['title' => 'Eid sale'])->assertOk()->assertJsonPath('status', 'draft')->assertJsonPath('pinned', true);
        $this->getJson('/api/store/promotions')->assertOk()->assertJsonCount(0);
    }

    public function test_store_layout_accepts_only_owned_rows_of_matching_layout(): void
    {
        $seller = $this->seller('layout@example.test', 'layout-store');
        $other = $this->seller('other@example.test', 'other-store');
        $token = $seller->createToken('test', ['seller:basic'])->plainTextToken;
        $mine = SellerPromotion::create(['seller_id' => $seller->id, 'title' => 'Mine', 'layout' => 'two', 'blocks' => [], 'status' => 'approved']);
        $theirs = SellerPromotion::create(['seller_id' => $other->id, 'title' => 'Theirs', 'layout' => 'two', 'blocks' => [], 'status' => 'approved']);
        $section = ['id' => 'offer-1', 'type' => 'offerTwo', 'enabled' => true, 'groupId' => (string) $mine->id];
        $this->withToken($token)->postJson('/api/seller/store-layout', ['sections' => [$section]])->assertOk()->assertJsonPath('sections.0.groupId', (string) $mine->id);
        $this->withToken($token)->getJson('/api/seller/store-layout')->assertOk()->assertJsonPath('sections.0.type', 'offerTwo');
        $this->getJson('/api/store/stores/layout-store/layout')->assertOk()->assertJsonPath('sections.0.groupId', (string) $mine->id);
        $section['groupId'] = (string) $theirs->id;
        $this->withToken($token)->postJson('/api/seller/store-layout', ['sections' => [$section]])->assertUnprocessable()->assertJsonValidationErrors('sections.0.groupId');
        $section['groupId'] = (string) $mine->id; $section['type'] = 'offerWide';
        $this->withToken($token)->postJson('/api/seller/store-layout', ['sections' => [$section]])->assertUnprocessable()->assertJsonValidationErrors('sections.0.groupId');
    }

    private function block(string $title): array { return ['title' => $title, 'body' => 'Copy', 'cta' => 'Shop', 'href' => '/offers', 'image' => '/assets/offer.png', 'theme' => 'violet']; }
    private function seller(string $email, string $slug): Seller { return Seller::create(['seller_name' => $slug, 'email' => $email, 'store_name' => $slug, 'store_slug' => $slug, 'kyc_type' => 'nid', 'kyc_number' => '123456', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']); }
}
