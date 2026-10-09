<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsoleModerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_manages_reports_and_fraud_rules(): void
    {
        $customer = Customer::create(['name' => 'Reporter', 'email' => 'reporter@example.test', 'password' => 'password123']);
        $category = ProductCategory::create(['name' => 'Clothes', 'slug' => 'clothes']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Reported item', 'slug' => 'reported-item', 'product_type' => 'simple', 'status' => 'active']);
        $customerToken = $customer->createToken('test', ['customer:basic'])->plainTextToken;
        $reportId = $this->withToken($customerToken)->postJson('/api/customers/reports', ['target_type' => 'product', 'target_id' => $product->id, 'product_id' => $product->id, 'reason' => 'Counterfeit', 'note' => 'Please investigate'])->assertCreated()->json('id');
        $duplicateReportId = $this->withToken($customerToken)->postJson('/api/customers/reports', ['target_type' => 'product', 'target_id' => $product->id, 'product_id' => $product->id, 'reason' => 'Misleading', 'note' => 'Second flag'])->assertCreated()->json('id');
        $admin = Admin::create(['name' => 'Super', 'email' => 'moderator@example.test', 'password' => 'password123', 'role' => 'super_admin']);
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $this->withToken($token)->getJson('/api/admin/reports?status=open')->assertOk()->assertJsonPath('data.0.id', $reportId)->assertJsonPath('data.0.target_summary.label', 'Reported item')->assertJsonPath('data.0.target_summary.href', '/products/reported-item');
        $this->withToken($token)->postJson("/api/admin/reports/{$reportId}/resolve", ['status' => 'actioned', 'resolution' => 'Listing removed.'])->assertOk()->assertJsonPath('status', 'actioned');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => 'unlisted']);
        $this->assertDatabaseHas('content_reports', ['id' => $duplicateReportId, 'status' => 'actioned']);
        $review = Review::create(['product_id' => $product->id, 'customer_id' => $customer->id, 'rating' => 5, 'comment' => 'Fine', 'seller_response' => 'Thanks']);
        $sellerResponseReport = $this->withToken($customerToken)->postJson('/api/customers/reports', ['target_type' => 'seller_response', 'target_id' => $review->id, 'product_id' => $product->id, 'reason' => 'Abusive'])->assertCreated()->json('id');
        $this->withToken($token)->postJson("/api/admin/reports/{$sellerResponseReport}/resolve", ['status' => 'actioned'])->assertOk();
        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'seller_response' => null]);
        $ruleId = $this->withToken($token)->postJson('/api/admin/fraud-guard/rules', ['name' => 'High value review', 'rule_type' => 'order_value', 'configuration' => ['threshold' => 5000], 'is_active' => true])->assertCreated()->json('id');
        $this->withToken($token)->postJson("/api/admin/fraud-guard/rules/{$ruleId}", ['is_active' => false])->assertOk()->assertJsonPath('is_active', false);
        $this->withToken($token)->postJson("/api/admin/fraud-guard/rules/{$ruleId}/delete")->assertOk();
        $this->withToken($token)->getJson('/api/admin/fraud-guard')->assertOk()->assertJsonPath('settings.enabled', true);
        $this->withToken($token)->postJson('/api/admin/fraud-guard/settings', ['enabled' => true, 'ip_block' => true, 'device_block' => true, 'phone_blacklist' => true, 'fake_number_detection' => true])->assertOk();
        $this->withToken($token)->postJson('/api/admin/fraud-guard/blocks', ['kind' => 'phone', 'value' => '+880 1712 345678', 'reason' => 'Chargeback'])->assertOk()->assertJsonPath('blocks.0.value', '01712345678');
    }
}
