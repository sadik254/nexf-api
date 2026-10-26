<?php

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use App\Models\Coupon;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsAndCouponOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_offer_cannot_use_another_store_coupon(): void
    {
        $seller = $this->seller('one'); $other = $this->seller('two');
        $coupon = Coupon::create(['seller_id' => $other->id, 'code' => 'OTHER10', 'discount_kind' => 'order', 'discount_type' => 'percentage', 'discount_value' => 10]);
        $token = $seller->createToken('test', ['seller'])->plainTextToken;
        $this->withToken($token)->postJson('/api/seller/promotions', [
            'title' => 'Coupons', 'layout' => 'coupons', 'blocks' => [['title' => 'Save', 'body' => 'Terms', 'cta' => 'OTHER10', 'coupon_id' => $coupon->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors('blocks.0.coupon_id');
    }

    public function test_analytics_tracks_sessions_and_house_filter(): void
    {
        // A normal site page must not inflate the house store's traffic.
        AnalyticsEvent::create(['session_id' => 'site-a', 'visitor_id' => 'a', 'event' => 'page_view', 'channel' => 'Direct']);
        AnalyticsEvent::create(['session_id' => 'house-a', 'visitor_id' => 'a', 'store_type' => 'house', 'event' => 'product_view', 'channel' => 'Direct']);
        AnalyticsEvent::create(['session_id' => 'house-a', 'visitor_id' => 'a', 'store_type' => 'house', 'event' => 'order_completed']);
        $seller = $this->seller('analytics');
        AnalyticsEvent::create(['session_id' => 'seller-a', 'seller_id' => $seller->id, 'event' => 'page_view']);
        $admin = \App\Models\Admin::create(['name' => 'Admin', 'email' => 'analytics-admin@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $token = $admin->createToken('test', ['admin'])->plainTextToken;
        $this->withToken($token)->getJson('/api/admin/analytics?store=house')->assertOk()->assertJsonPath('sessions', 1)->assertJsonPath('orders', 1)->assertJsonPath('conversion', 100)->assertJsonPath('session_metric', 'store_engaged_sessions');
    }

    public function test_analytics_uses_cloudflare_location_when_the_browser_does_not_send_one(): void
    {
        $this->withHeader('CF-IPCity', 'Dhaka%20North')->postJson('/api/analytics/events', [
            'session_id' => 'location-a', 'event' => 'page_view', 'store_type' => 'house',
        ])->assertCreated();
        $this->assertDatabaseHas('analytics_events', ['session_id' => 'location-a', 'location' => 'Dhaka North', 'store_type' => 'house']);
    }

    private function seller(string $suffix): Seller
    {
        return Seller::create(['seller_name' => "Seller {$suffix}", 'email' => "{$suffix}@example.test", 'store_name' => "Store {$suffix}", 'store_slug' => "store-{$suffix}", 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
    }
}
