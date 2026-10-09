<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\HomepageNotice;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLot;
use App\Models\ProductVariation;
use App\Models\Seller;
use App\Models\StoreChat;
use App\Models\SupportTicket;
use App\Services\ProductHtmlSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsoleDesignApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_counts_are_scoped_and_do_not_require_order_abilities(): void
    {
        $admin = Admin::create(['name' => 'Editor', 'email' => 'editor-console@example.test', 'password' => 'password123', 'role' => 'editor', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Buyer', 'email' => 'buyer-console@example.test', 'password' => 'password123']);
        $sellers = collect([1, 2])->map(fn ($i) => Seller::create([
            'seller_name' => "Seller {$i}", 'email' => "seller-console-{$i}@example.test", 'store_name' => "Store {$i}", 'store_slug' => "console-store-{$i}",
            'kyc_type' => 'nid', 'kyc_number' => "{$i}", 'kyc_document_url' => 'https://example.test/kyc', 'product_category' => 'Clothing',
            'status' => 'approved', 'is_active' => true, 'password' => 'password123',
        ]));
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']);
        foreach ($sellers as $seller) {
            SupportTicket::create(['customer_id' => $customer->id, 'seller_id' => $seller->id, 'category' => 'Order', 'subject' => 'Help', 'status' => 'open']);
            $chat = StoreChat::create(['customer_id' => $customer->id, 'seller_id' => $seller->id, 'store_key' => "seller:{$seller->id}"]);
            $chat->messages()->create(['author_type' => 'customer', 'author_id' => $customer->id, 'body' => 'Unread']);
            Product::create(['category_id' => $category->id, 'seller_id' => $seller->id, 'name' => 'Simple', 'slug' => "console-simple-{$seller->id}", 'product_type' => 'simple', 'status' => 'active']);
        }
        $product = Product::create(['category_id' => $category->id, 'seller_id' => $sellers[0]->id, 'name' => 'Variable', 'slug' => 'console-variable', 'product_type' => 'variable', 'status' => 'active']);
        $empty = ProductVariation::create(['product_id' => $product->id, 'attributes' => ['size' => 'S'], 'is_active' => true]);
        $stocked = ProductVariation::create(['product_id' => $product->id, 'attributes' => ['size' => 'M'], 'is_active' => true]);
        ProductLot::create(['variation_id' => $stocked->id, 'lot_number' => 'C-1', 'quantity' => 4, 'quantity_remaining' => 4, 'buying_price' => 10, 'selling_price' => 20]);
        $token = $sellers[0]->createToken('test', ['seller:basic'])->plainTextToken;
        $this->withToken($token)->getJson('/api/seller/console-summary')->assertOk()->assertJsonPath('support', 1)->assertJsonPath('chats', 1)->assertJsonPath('inventory', 2);
        StoreChat::where('seller_id', $sellers[0]->id)->update(['store_read_at' => now()->addMinute()]);
        $this->withToken($token)->getJson('/api/seller/console-summary')->assertOk()->assertJsonPath('chats', 0);
        $token = $admin->createToken('test', ['admin:basic', 'admin:coupons'])->plainTextToken;
        $this->withToken($token)->getJson('/api/admin/console-summary')->assertOk()->assertJsonPath('support', 2)->assertJsonPath('returns', 0)->assertJsonPath('chats', 0)->assertJsonPath('inventory', 3);
        $token = $customer->createToken('test', ['customer:basic'])->plainTextToken;
        $this->withToken($token)->getJson('/api/admin/console-summary')->assertForbidden();
    }

    public function test_notice_reorder_is_atomic_and_requires_the_complete_list_and_super_admin(): void
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => 'admin-console@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $a = HomepageNotice::create(['text' => 'First', 'sort_order' => 8]);
        $b = HomepageNotice::create(['text' => 'Second', 'sort_order' => 9]);
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $this->withToken($token)->postJson('/api/admin/homepage/notices/reorder', ['ids' => [$b->id, $a->id]])->assertOk();
        $this->assertSame([$b->id, $a->id], HomepageNotice::orderBy('sort_order')->pluck('id')->all());
        $this->withToken($token)->postJson('/api/admin/homepage/notices/reorder', ['ids' => [$a->id]])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/admin/homepage/notices/reorder', ['ids' => [$a->id, $a->id]])->assertUnprocessable();
        $this->assertSame([$b->id, $a->id], HomepageNotice::orderBy('sort_order')->pluck('id')->all());
        $admin->update(['role' => 'admin']);
        $this->withToken($token)->postJson('/api/admin/homepage/notices/reorder', ['ids' => [$a->id, $b->id]])->assertForbidden();
    }

    public function test_reference_badge_choices_and_empty_list_persist(): void
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => 'badge-console@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $this->withToken($token)->postJson('/api/admin/homepage/trust-badges', ['badges' => [['icon' => 'Headset', 'label' => 'Support', 'tone' => 'teal', 'hidden' => false]]])->assertOk()->assertJsonPath('0.icon', 'Headset')->assertJsonPath('0.tone', 'teal');
        $this->withToken($token)->postJson('/api/admin/homepage/trust-badges', ['badges' => []])->assertOk()->assertExactJson([]);
    }

    public function test_editor_colour_and_link_target_survive_without_unsafe_styles(): void
    {
        $html = app(ProductHtmlSanitizer::class)->clean('<p style="text-align:center;position:fixed"><font color="#ff0000">Colour</font><span style="color:rgb(1, 2, 3);background-image:url(javascript:alert(1))" onclick="alert(1)">Text</span><a href="https://example.test" target="_blank">Link</a></p>');
        $this->assertStringContainsString('color:#ff0000', $html);
        $this->assertStringContainsString('text-align:center', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        foreach (['position', 'background-image', 'onclick', 'javascript'] as $unsafe) $this->assertStringNotContainsString($unsafe, $html);
    }

    public function test_role_and_discount_filters_run_before_pagination(): void
    {
        $admin = Admin::create(['name' => 'Super', 'email' => 'filter-console@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        Admin::create(['name' => 'Editor', 'email' => 'filter-editor@example.test', 'password' => 'password123', 'role' => 'editor', 'is_active' => true]);
        $token = $admin->createToken('test', ['admin:manage-admins', 'admin:coupons'])->plainTextToken;
        $this->withToken($token)->getJson('/api/admin/admins?role=editor&per_page=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.role', 'editor');
        $this->withToken($token)->getJson('/api/admin/admins?role=unknown')->assertUnprocessable();
        foreach (['active', 'scheduled', 'expired', 'inactive'] as $status) {
            \App\Models\Coupon::create(['created_by_admin_id' => $admin->id, 'code' => strtoupper($status), 'discount_type' => 'fixed', 'discount_value' => 10, 'is_active' => $status !== 'inactive', 'starts_at' => $status === 'scheduled' ? now()->addDay() : null, 'expires_at' => $status === 'expired' ? now()->subDay() : null]);
        }
        foreach (['active', 'scheduled', 'expired', 'inactive'] as $status) {
            $this->withToken($token)->getJson("/api/admin/coupons?status={$status}&per_page=1")->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.code', strtoupper($status));
        }
    }

    public function test_automatic_discount_receives_a_unique_internal_code(): void
    {
        $admin = Admin::create(['name' => 'Discount admin', 'email' => 'auto-discount@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $token = $admin->createToken('test', ['admin:basic', 'admin:coupons'])->plainTextToken;
        $response = $this->withToken($token)->postJson('/api/admin/coupons', ['code' => 'AUTO', 'is_automatic' => true, 'name' => 'Automatic order saving', 'discount_type' => 'percentage', 'discount_value' => 10, 'discount_kind' => 'order']);
        $response->assertCreated()->assertJsonPath('coupon.is_automatic', true);
        $this->assertStringStartsWith('AUTO-', $response->json('coupon.code'));
    }

    public function test_profile_picture_and_phone_can_be_cleared(): void
    {
        $admin = Admin::create(['name' => 'Profile', 'email' => 'profile-console@example.test', 'phone' => '01700000000', 'image' => 'https://example.test/portrait.jpg', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $this->withToken($token)->postJson('/api/admin/me', ['phone' => '', 'clear_image' => true])->assertOk()->assertJsonPath('admin.phone', null)->assertJsonPath('admin.image', null);
        $this->assertNull($admin->fresh()->image);
    }

    public function test_seller_sales_period_excludes_other_dates_and_cancelled_orders(): void
    {
        $admin = Admin::create(['name' => 'Sales', 'email' => 'sales-console@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $seller = Seller::create(['seller_name' => 'Seller', 'email' => 'sales-seller@example.test', 'store_name' => 'Store', 'store_slug' => 'sales-store', 'kyc_type' => 'nid', 'kyc_number' => '1', 'kyc_document_url' => 'https://example.test/kyc', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $customer = Customer::create(['name' => 'Buyer', 'email' => 'sales-buyer@example.test', 'password' => 'password123']);
        foreach ([['2026-09-15', 'delivered', 2], ['2026-08-15', 'delivered', 3], ['2026-09-20', 'cancelled', 4]] as $index => [$date, $status, $qty]) {
            $order = \App\Models\Order::create(['customer_id' => $customer->id, 'order_number' => "CONSOLE-SALES-{$index}", 'status' => $status, 'payment_status' => 'paid', 'subtotal' => 100 * $qty, 'total' => 100 * $qty, 'shipping_charge' => 0, 'shipping_name' => 'Buyer', 'shipping_phone' => '01700000000', 'shipping_address' => 'Dhaka']);
            $order->forceFill(['created_at' => "{$date} 12:00:00"])->save();
            \App\Models\OrderItem::create(['order_id' => $order->id, 'seller_id' => $seller->id, 'product_name' => 'Coat', 'quantity' => $qty, 'unit_selling_price' => 100, 'unit_buying_price' => 50, 'line_subtotal' => 100 * $qty, 'line_cost' => 50 * $qty, 'line_profit' => 50 * $qty, 'fulfillment_status' => $status === 'cancelled' ? 'cancelled' : 'delivered']);
        }
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $response = $this->withToken($token)->getJson('/api/admin/sellers?from=2026-09-01&to=2026-09-30');
        $response->assertOk()->assertJsonPath('sales_period.from', '2026-09-01')->assertJsonPath('data.0.units_sold', 2);
        $this->assertEquals(200, $response->json('data.0.revenue'));
        $this->withToken($token)->getJson('/api/admin/sellers?from=2026-10-01&to=2026-09-30')->assertUnprocessable();
    }
    public function test_reference_category_and_offer_colours_persist(): void
    {
        $admin = Admin::create(['name' => 'Colours', 'email' => 'colours-console@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $this->withToken($token)->postJson('/api/admin/homepage/category-cards', ['cards' => [['name' => 'Clothing', 'href' => '/shop', 'image' => 'https://example.test/card.png', 'theme' => 'fuchsia', 'hidden' => false]]])->assertOk()->assertJsonPath('0.theme', 'fuchsia');
        $offer = \App\Models\HomepageOfferBlock::where('row_type', 'wide')->firstOrFail();
        $this->withToken($token)->postJson("/api/admin/homepage/offer-blocks/{$offer->id}", ['theme' => 'stone'])->assertOk()->assertJsonPath('theme', 'stone');
    }

    public function test_new_offer_set_starts_with_all_editable_slots(): void
    {
        $admin = Admin::create(['name' => 'Offers', 'email' => 'offer-set@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $this->withToken($token)->postJson('/api/admin/homepage/offer-sets', ['row_type' => 'four', 'name' => 'Winter campaign'])
            ->assertCreated()->assertJsonCount(4, 'blocks')->assertJsonPath('blocks.0.hidden', true);
    }
}
