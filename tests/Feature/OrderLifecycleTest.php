<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\FraudGuardBlock;
use App\Models\FraudGuardSetting;
use App\Models\ProductCategory;
use App\Models\ProductLot;
use App\Models\Seller;
use App\Models\ShippingMethod;
use App\Models\OrderItem;
use App\Mail\OrderNotificationMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_lifecycle_times_record_real_transitions_without_restamping_history(): void
    {
        $order = \App\Models\Order::create(['order_number'=>'ISOLATED-TIMELINE', 'status'=>'pending','payment_status'=>'unpaid','subtotal'=>10,'shipping_charge'=>0,'discount_total'=>0,'total'=>10,'shipping_name'=>'Timeline tester','shipping_phone'=>'01700000000','shipping_address'=>'Test address']);
        $item = $order->items()->create(['product_name'=>'Timeline item','quantity'=>1,'unit_selling_price'=>10,'unit_buying_price'=>0,'line_subtotal'=>10,'line_cost'=>0,'line_profit'=>10,'fulfillment_status'=>'pending']);
        $this->assertNull($order->payment_paid_at);$this->assertNull($item->confirmed_at);
        $this->travelTo(now()->startOfSecond());
        $order->update(['payment_status'=>'paid']);$item->update(['fulfillment_status'=>'confirmed']);
        $paid = $order->fresh()->payment_paid_at;$confirmed = $item->fresh()->confirmed_at;
        $this->assertNotNull($paid);$this->assertNotNull($confirmed);
        $this->travel(1)->hours();$order->update(['notes'=>'Changed note']);$item->update(['tracking_number'=>'Local reference']);
        $this->assertTrue($order->fresh()->payment_paid_at->equalTo($paid));$this->assertTrue($item->fresh()->confirmed_at->equalTo($confirmed));
        $this->travelBack();
    }

    public function test_coupon_validation_reports_the_exact_unavailable_reason(): void
    {
        $base = [
            'discount_type' => 'fixed',
            'discount_value' => 50,
        ];

        Coupon::create($base + ['code' => 'INACTIVE', 'is_active' => false]);
        Coupon::create($base + ['code' => 'FUTURE', 'is_active' => true, 'starts_at' => now()->addHour()]);
        Coupon::create($base + ['code' => 'EXPIRED', 'is_active' => true, 'expires_at' => now()->subHour()]);
        Coupon::create($base + ['code' => 'EXHAUSTED', 'is_active' => true, 'usage_limit' => 1, 'used_count' => 1]);

        foreach ([
            'MISSING' => 'Coupon code was not found.',
            'INACTIVE' => 'Coupon is inactive.',
            'FUTURE' => 'Coupon is not active yet.',
            'EXPIRED' => 'Coupon has expired.',
            'EXHAUSTED' => 'Coupon usage limit has been reached.',
        ] as $code => $message) {
            $this->postJson('/api/coupons/validate', ['code' => $code, 'subtotal' => 1280])
                ->assertUnprocessable()
                ->assertJsonPath('message', $message);
        }
    }

    public function test_valid_coupon_can_be_serialized_in_validation_response(): void
    {
        Coupon::create([
            'code' => 'NEW50',
            'discount_type' => 'fixed',
            'discount_value' => 50,
            'is_active' => true,
        ]);

        $this->postJson('/api/coupons/validate', ['code' => 'NEW50', 'subtotal' => 1280])
            ->assertOk()
            ->assertJsonPath('coupon.code', 'NEW50')
            ->assertJsonPath('discount_amount', 50);
    }

    public function test_unlisted_product_can_be_purchased_but_inactive_product_cannot(): void
    {
        [$customer, $product] = $this->checkoutFixtures(2);
        $product->update(['status' => 'unlisted']);
        $this->placeOrder($customer, $product, 1)->assertCreated();
        $product->update(['status' => 'inactive']);
        $this->placeOrder($customer, $product, 1)->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_checkout_snapshots_images_and_prevents_overselling(): void
    {
        [$customer, $product] = $this->checkoutFixtures(2);

        $response = $this->placeOrder($customer, $product, 2);
        $response->assertCreated()
            ->assertJsonPath('order.items.0.product_thumbnail', 'https://ucarecdn.com/product/-/preview/')
            ->assertJsonPath('order.items.0.product_image_variants.thumbnail.thumb', 'https://ucarecdn.com/product/-/scale_crop/256x256/smart/-/format/auto/');

        $this->assertDatabaseHas('product_lots', ['product_id' => $product->id, 'quantity_remaining' => 0]);

        $this->placeOrder($customer, $product, 1)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');
    }

    public function test_coupon_per_customer_limit_and_admin_cancellation_restore_stock(): void
    {
        [$customer, $product] = $this->checkoutFixtures(2);
        Coupon::create([
            'code' => 'ONCE',
            'discount_type' => 'fixed',
            'discount_value' => 10,
            'per_customer_limit' => 1,
            'is_active' => true,
        ]);

        $order = $this->placeOrder($customer, $product, 1, 'ONCE')->assertCreated()->json('order');
        $this->placeOrder($customer, $product, 1, 'ONCE')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('coupon_code');

        $admin = Admin::create([
            'name' => 'Order Admin',
            'email' => 'order-admin@example.test',
            'password' => 'password123',
            'role' => 'super_admin',
        ]);
        $token = $admin->createToken('test', ['admin:orders'])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/admin/orders/{$order['id']}/cancel")
            ->assertOk()
            ->assertJsonPath('order.status', 'cancelled')
            ->assertJsonPath('order.items.0.fulfillment_status', 'cancelled');

        $this->assertDatabaseHas('product_lots', ['product_id' => $product->id, 'quantity_remaining' => 2]);
        $this->assertDatabaseHas('product_lot_movements', ['reason' => 'order_cancellation', 'quantity_change' => 1]);
    }

    public function test_free_shipping_discount_removes_the_live_shipping_quote(): void
    {
        [$customer, $product] = $this->checkoutFixtures(1);
        Coupon::create(['code' => 'FREESHIP', 'discount_kind' => 'shipping', 'discount_type' => 'percentage', 'discount_value' => 0, 'is_active' => true]);
        $token = $customer->createToken('test', ['customer:basic'])->plainTextToken;
        $this->withToken($token)->postJson('/api/customers/orders/preview', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'payment_method_id' => PaymentMethod::firstOrFail()->id, 'shipping_method_id' => ShippingMethod::firstOrFail()->id, 'coupon_code' => 'FREESHIP', 'shipping_phone' => '01700000000'])
            ->assertOk()->assertJsonPath('shipping_charge', 20)->assertJsonPath('discount_total', 20)->assertJsonPath('total', 100);
    }

    public function test_buy_x_get_y_discount_only_rewards_the_configured_quantity(): void
    {
        [$customer, $product] = $this->checkoutFixtures(4);
        $coupon = Coupon::create(['code' => 'BUYGET', 'discount_kind' => 'bxgy', 'discount_type' => 'percentage', 'discount_value' => 100, 'buy_product_ids' => [$product->id], 'eligible_product_ids' => [$product->id], 'buy_quantity' => 1, 'get_quantity' => 1, 'uses_per_order' => 1, 'reward_type' => 'free', 'is_active' => true]);
        $token = $customer->createToken('test', ['customer:basic'])->plainTextToken;
        $payload = ['items' => [['product_id' => $product->id, 'quantity' => 4]], 'payment_method_id' => PaymentMethod::where('code', 'cod')->firstOrFail()->id, 'shipping_method_id' => ShippingMethod::firstOrFail()->id, 'coupon_code' => 'BUYGET', 'shipping_phone' => '01700000000'];
        $this->withToken($token)->postJson('/api/customers/orders/preview', $payload)
            ->assertOk()->assertJsonPath('subtotal', 400)->assertJsonPath('discount_total', 100)->assertJsonPath('total', 320);
        $coupon->update(['uses_per_order' => 2]);
        $this->withToken($token)->postJson('/api/customers/orders/preview', $payload)
            ->assertOk()->assertJsonPath('discount_total', 200)->assertJsonPath('total', 220);

        $coupon->update(['code' => 'BUYGETONE']);
        $payload['items'][0]['quantity'] = 1;
        $this->withToken($token)->postJson('/api/customers/orders/preview', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('coupon_code');
    }

    public function test_product_discount_can_target_a_collection(): void
    {
        [$customer, $product] = $this->checkoutFixtures(1);
        $collection = ProductCollection::create(['name' => 'Winter savings']);
        $collection->products()->attach($product->id, ['sort_order' => 0]);
        Coupon::create(['code' => 'COLLECTION10', 'discount_kind' => 'products', 'discount_type' => 'fixed', 'discount_value' => 10, 'eligible_collection_ids' => [$collection->id], 'is_active' => true]);
        $token = $customer->createToken('test', ['customer:basic'])->plainTextToken;
        $this->withToken($token)->postJson('/api/customers/orders/preview', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'payment_method_id' => PaymentMethod::firstOrFail()->id, 'shipping_method_id' => ShippingMethod::firstOrFail()->id, 'coupon_code' => 'COLLECTION10', 'shipping_phone' => '01700000000'])
            ->assertOk()->assertJsonPath('discount_total', 10)->assertJsonPath('total', 110);
    }

    public function test_device_block_stops_checkout_preview(): void
    {
        [$customer, $product] = $this->checkoutFixtures(1);
        FraudGuardSetting::create(['enabled' => true, 'ip_block' => false, 'device_block' => true, 'phone_blacklist' => false, 'fake_number_detection' => false]);
        FraudGuardBlock::create(['kind' => 'device', 'value' => '0123456789abcdef']);
        $token = $customer->createToken('test', ['customer:basic'])->plainTextToken;
        $this->withToken($token)->postJson('/api/customers/orders/preview', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'payment_method_id' => PaymentMethod::firstOrFail()->id, 'shipping_method_id' => ShippingMethod::firstOrFail()->id, 'shipping_phone' => '01700000000', 'device_id' => '0123456789abcdef'])
            ->assertUnprocessable()->assertJsonValidationErrors('checkout');
    }

    public function test_seller_can_only_view_and_transition_own_order_items(): void
    {
        config(['services.steadfast.api_key' => 'test-key', 'services.steadfast.secret_key' => 'test-secret', 'services.steadfast.base_url' => 'https://steadfast.test/api/v1']);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'bulk-order')) {
                $items = json_decode($request['data'], true);
                return Http::response(['data' => collect($items)->map(fn ($item) => ['invoice' => $item['invoice'], 'consignment_id' => 1, 'tracking_code' => 'TRACK-API', 'status' => 'success'])->all()], 200);
            }
            return Http::response(['status' => 200, 'consignment' => ['consignment_id' => 1, 'invoice' => 'INV-1', 'tracking_code' => 'TRACK-API', 'status' => 'in_review']], 200);
        });
        [$customer, $product] = $this->checkoutFixtures(1, true);
        $order = $this->placeOrder($customer, $product, 1)->assertCreated()->json('order');
        $seller = $product->seller;
        $token = $seller->createToken('test', ['seller:basic'])->plainTextToken;
        $itemId = $order['items'][0]['id'];

        $this->withToken($token)->getJson('/api/seller/orders')
            ->assertOk()
            ->assertJsonPath('data.0.items.0.id', $itemId);

        $this->withToken($token)
            ->postJson("/api/seller/orders/{$order['id']}/items/{$itemId}/fulfillment", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('order.status', 'confirmed')
            ->assertJsonPath('order.items.0.fulfillment_status', 'confirmed');

        $this->withToken($token)
            ->postJson("/api/seller/orders/{$order['id']}/items/{$itemId}/fulfillment", [
                'status' => 'shipped',
                'courier_provider' => 'Pathao Courier',
                'tracking_number' => 'PATHAO-123',
            ])
            ->assertOk()
            ->assertJsonPath('order.status', 'shipped')
            ->assertJsonPath('order.items.0.courier_provider', 'Pathao Courier')
            ->assertJsonPath('order.items.0.tracking_number', 'PATHAO-123')
            ->assertJsonPath('order.items.0.courier_consignment_id', null);

        $this->withToken($token)
            ->postJson("/api/seller/orders/{$order['id']}/items/{$itemId}/fulfillment", ['status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('order.status', 'delivered');
    }

    public function test_super_admin_can_transition_seller_items_without_steadfast(): void
    {
        Http::fake();
        [$customer, $product] = $this->checkoutFixtures(1, true);
        $order = $this->placeOrder($customer, $product, 1)->assertCreated()->json('order');
        $itemId = $order['items'][0]['id'];
        $sellerId = $product->seller_id;
        $admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'seller-order-admin@example.test',
            'password' => 'password123',
            'role' => 'super_admin',
        ]);
        $token = $admin->createToken('test', ['admin:orders'])->plainTextToken;
        $endpoint = "/api/admin/sellers/{$sellerId}/orders/{$order['id']}/items/{$itemId}/fulfillment";

        $this->withToken($token)->postJson($endpoint, ['status' => 'confirmed'])
            ->assertOk()->assertJsonPath('order.items.0.fulfillment_status', 'confirmed');
        $this->withToken($token)->postJson("/api/admin/orders/{$order['id']}/items/{$itemId}/fulfillment", ['status' => 'shipped'])
            ->assertUnprocessable();
        $this->withToken($token)->postJson('/api/admin/orders/bulk-ship', ['order_item_ids' => [$itemId]])
            ->assertUnprocessable();
        $this->withToken($token)->postJson($endpoint, [
            'status' => 'shipped',
            'courier_provider' => 'Pathao Courier',
            'tracking_number' => 'PATHAO-ADMIN-1',
        ])->assertOk()
            ->assertJsonPath('order.items.0.courier_provider', 'Pathao Courier')
            ->assertJsonPath('order.items.0.courier_consignment_id', null);

        Http::assertNothingSent();
    }

    public function test_products_referenced_by_orders_cannot_be_deleted(): void
    {
        [$customer, $product] = $this->checkoutFixtures(1);
        $this->placeOrder($customer, $product, 1)->assertCreated();

        $admin = Admin::create([
            'name' => 'Catalog Admin',
            'email' => 'catalog-admin@example.test',
            'password' => 'password123',
            'role' => 'super_admin',
        ]);

        $this->withToken($admin->createToken('test', ['admin:basic'])->plainTextToken)
            ->postJson("/api/admin/products/{$product->id}/delete")
            ->assertUnprocessable();
    }

    public function test_customer_can_preview_and_cancel_a_pending_order(): void
    {
        Mail::fake();
        [$customer, $product] = $this->checkoutFixtures(1);
        $token = $customer->createToken('test', ['customer:basic'])->plainTextToken;
        $payload = [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method_id' => PaymentMethod::firstOrFail()->id,
            'shipping_method_id' => ShippingMethod::firstOrFail()->id,
        ];
        $this->withToken($token)->postJson('/api/customers/orders/preview', $payload)
            ->assertOk()->assertJsonPath('subtotal', 100)->assertJsonPath('total', 120);

        $order = $this->placeOrder($customer, $product, 1)->assertCreated()->json('order');
        Mail::assertSent(OrderNotificationMail::class);
        $this->withToken($token)->postJson("/api/customers/orders/{$order['id']}/cancel")
            ->assertOk()->assertJsonPath('order.status', 'cancelled');
    }

    public function test_checkout_charges_delivery_once_per_distinct_store(): void
    {
        [$customer, $firstProduct] = $this->checkoutFixtures(2, true);
        $secondSeller = Seller::create([
            'seller_name' => 'Second Seller',
            'email' => 'second-seller@example.test',
            'store_name' => 'Second Store',
            'store_slug' => 'second-store',
            'kyc_type' => 'nid',
            'kyc_number' => '654321',
            'kyc_document_url' => 'https://example.test/second-kyc',
            'product_category' => 'Category',
            'status' => 'approved',
            'is_active' => true,
            'password' => 'password123',
        ]);
        $secondProduct = Product::create([
            'seller_id' => $secondSeller->id,
            'category_id' => $firstProduct->category_id,
            'name' => 'Second Product',
            'slug' => 'second-product',
            'product_type' => 'simple',
            'status' => 'active',
            'default_selling_price' => 60,
        ]);
        ProductLot::create([
            'product_id' => $secondProduct->id,
            'lot_number' => 'LOT-2',
            'buying_price' => 40,
            'selling_price' => 60,
            'quantity' => 2,
            'quantity_remaining' => 2,
        ]);

        $token = $customer->createToken('test', ['customer:basic'])->plainTextToken;
        $payload = [
            'items' => [
                ['product_id' => $firstProduct->id, 'quantity' => 1],
                ['product_id' => $secondProduct->id, 'quantity' => 1],
            ],
            'payment_method_id' => PaymentMethod::firstOrFail()->id,
            'shipping_method_id' => ShippingMethod::firstOrFail()->id,
        ];

        $this->withToken($token)->postJson('/api/customers/orders/preview', $payload)
            ->assertOk()
            ->assertJsonPath('subtotal', 160)
            ->assertJsonCount(2, 'shipping_groups')
            ->assertJsonPath('shipping_charge', 40)
            ->assertJsonPath('total', 200);

        $sellerToken = $secondSeller->createToken('test', ['seller:basic'])->plainTextToken;
        $this->withToken($sellerToken)->postJson('/api/seller/delivery-rates', ['rates' => [[
            'shipping_method_id' => $payload['shipping_method_id'], 'charge' => 35,
        ]]])->assertOk()->assertJsonPath('0.charge', '35.00');
        $this->withToken($token)->postJson('/api/customers/orders/preview', $payload)
            ->assertOk()->assertJsonPath('shipping_charge', 55)->assertJsonPath('total', 215);

        $order = $this->withToken($token)->postJson('/api/customers/orders', $payload + [
            'shipping_name' => 'Customer',
            'shipping_phone' => '01700000000',
            'shipping_address' => 'Dhaka',
        ])->assertCreated()
            ->assertJsonCount(2, 'order.store_groups')
            ->assertJsonPath('order.shipping_charge', '55.00')
            ->assertJsonPath('order.total', '215.00')
            ->json('order');

        $this->assertDatabaseCount('order_store_groups', 2);
        $this->assertDatabaseHas('order_store_groups', [
            'order_id' => $order['id'],
            'seller_id' => $firstProduct->seller_id,
            'shipping_charge' => 20,
        ]);
        $this->assertDatabaseHas('order_store_groups', [
            'order_id' => $order['id'],
            'seller_id' => $secondSeller->id,
            'shipping_charge' => 35,
        ]);
    }

    public function test_steadfast_webhook_requires_authentication_and_acknowledges_unknown_consignment(): void
    {
        config(['services.steadfast.webhook_token' => 'webhook-test-token']);
        $payload = ['notification_type' => 'delivery_status', 'consignment_id' => 999, 'invoice' => 'UNKNOWN', 'status' => 'delivered'];
        $this->postJson('/api/webhooks/steadfast', $payload)->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer webhook-test-token')
            ->postJson('/api/webhooks/steadfast', $payload)
            ->assertOk()->assertJsonPath('message', 'Webhook received successfully.');
        $this->assertDatabaseHas('courier_webhook_events', [
            'consignment_id' => '999',
            'processing_error' => 'No matching local consignment; event ignored.',
        ]);
    }

    public function test_admin_delete_archives_a_pending_order_and_restores_inventory(): void
    {
        [$customer, $product] = $this->checkoutFixtures(1);
        $order = $this->placeOrder($customer, $product, 1)->assertCreated()->json('order');
        $admin = Admin::create([
            'name' => 'Order Admin',
            'email' => 'delete-order-admin@example.test',
            'password' => 'password123',
            'role' => 'super_admin',
        ]);
        $token = $admin->createToken('test', ['admin:orders'])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/admin/orders/{$order['id']}/delete")
            ->assertOk();

        $this->assertSoftDeleted('orders', ['id' => $order['id']]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order['id'],
            'fulfillment_status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('product_lots', [
            'product_id' => $product->id,
            'quantity_remaining' => 1,
        ]);
        $this->withToken($token)
            ->getJson("/api/admin/orders/{$order['id']}")
            ->assertNotFound();
    }

    public function test_admin_can_persist_the_reference_packed_stage_after_confirmation(): void
    {
        [$customer, $product] = $this->checkoutFixtures(1);
        $order = $this->placeOrder($customer, $product, 1)->assertCreated()->json('order');
        $admin = Admin::create([
            'name' => 'Packing Admin',
            'email' => 'packing-admin@example.test',
            'password' => 'password123',
            'role' => 'super_admin',
        ]);
        $token = $admin->createToken('test', ['admin:orders'])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/admin/orders/{$order['id']}/items/{$order['items'][0]['id']}/fulfillment", ['status' => 'confirmed'])
            ->assertOk();
        $this->withToken($token)
            ->postJson("/api/admin/orders/{$order['id']}", ['packed' => true])
            ->assertOk()
            ->assertJsonPath('order.status', 'confirmed')
            ->assertJsonPath('order.packed_at', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_admin_can_create_a_guest_order_through_the_checkout_contract(): void
    {
        [$customer, $product] = $this->checkoutFixtures(1);
        $admin = Admin::create(['name' => 'Create Admin', 'email' => 'create-admin@example.test', 'password' => 'password123', 'role' => 'super_admin']);
        $token = $admin->createToken('test', ['admin:orders'])->plainTextToken;
        $this->withToken($token)->postJson('/api/admin/orders', [
            'guest' => true,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method_id' => PaymentMethod::firstOrFail()->id,
            'shipping_method_id' => ShippingMethod::firstOrFail()->id,
            'shipping_name' => 'Walk-in Customer',
            'shipping_phone' => '01711111111',
            'shipping_address' => 'Dhaka',
        ])->assertCreated()->assertJsonPath('order.is_guest', true);
    }

    public function test_steadfast_cancellation_waits_for_reconciliation_and_duplicate_events_are_safe(): void
    {
        config(['services.steadfast.webhook_token' => 'webhook-test-token']);
        [$customer, $product] = $this->checkoutFixtures(1);
        $order = $this->placeOrder($customer, $product, 1)->assertCreated()->json('order');
        $item = OrderItem::findOrFail($order['items'][0]['id']);
        $item->update(['fulfillment_status' => 'shipped', 'courier_provider' => 'steadfast', 'courier_consignment_id' => '12345', 'courier_invoice' => 'INV-12345']);
        $payload = ['notification_type' => 'delivery_status', 'consignment_id' => 12345, 'invoice' => 'INV-12345', 'status' => 'cancelled', 'tracking_message' => 'Returned by courier'];
        $this->withHeader('Authorization', 'Bearer webhook-test-token')->postJson('/api/webhooks/steadfast', $payload)->assertOk();
        $this->assertDatabaseHas('order_items', ['id' => $item->id, 'fulfillment_status' => 'return_pending']);
        $this->assertDatabaseHas('product_lots', ['product_id' => $product->id, 'quantity_remaining' => 0]);
        $this->withHeader('Authorization', 'Bearer webhook-test-token')->postJson('/api/webhooks/steadfast', $payload)->assertOk();
        $admin = Admin::create(['name' => 'Reconciliation Admin', 'email' => 'reconcile@example.test', 'password' => 'password123', 'role' => 'super_admin']);
        $this->withToken($admin->createToken('test', ['admin:orders'])->plainTextToken)
            ->postJson("/api/admin/orders/{$order['id']}/items/{$item->id}/reconcile-return")
            ->assertOk()->assertJsonPath('order.items.0.fulfillment_status', 'returned');
        $this->assertDatabaseHas('product_lots', ['product_id' => $product->id, 'quantity_remaining' => 1]);
        $this->assertDatabaseHas('product_lot_movements', ['reason' => 'return_reconciliation', 'quantity_change' => 1]);
    }

    public function test_steadfast_delivery_webhook_marks_item_delivered(): void
    {
        config(['services.steadfast.webhook_token' => 'webhook-test-token']);
        [$customer, $product] = $this->checkoutFixtures(1);
        $order = $this->placeOrder($customer, $product, 1)->assertCreated()->json('order');
        $item = OrderItem::findOrFail($order['items'][0]['id']);
        $item->update(['fulfillment_status' => 'shipped', 'courier_provider' => 'steadfast', 'courier_consignment_id' => '54321', 'courier_invoice' => 'INV-54321']);
        $this->withHeader('Authorization', 'Bearer webhook-test-token')->postJson('/api/webhooks/steadfast', ['notification_type' => 'delivery_status', 'consignment_id' => 54321, 'invoice' => 'INV-54321', 'status' => 'delivered', 'tracking_message' => 'Delivered'])->assertOk();
        $this->assertDatabaseHas('order_items', ['id' => $item->id, 'fulfillment_status' => 'delivered', 'courier_tracking_message' => 'Delivered']);
    }

    public function test_store_delivery_options_persist_and_checkout_uses_only_that_stores_options(): void
    {
        Mail::fake();
        [$customer,$product]=$this->checkoutFixtures(4);
        $seller=Seller::create(['seller_name'=>'Delivery Owner','email'=>'delivery-owner@example.test','store_name'=>'Delivery Owner Store','store_slug'=>'delivery-owner','kyc_type'=>'nid','kyc_number'=>'owner','kyc_document_url'=>'https://example.test/id','product_category'=>'Clothing','status'=>'approved','is_active'=>true,'password'=>'password123']);
        $product->update(['seller_id'=>$seller->id]);
        $token=$seller->createToken('test',['seller:basic'])->plainTextToken;
        $this->withToken($token);
        $rates=$this->postJson('/api/seller/delivery-rates',['options'=>[['name'=>'Inside Dhaka','charge'=>31],['name'=>'Custom regional delivery','charge'=>57]]])->assertOk()->assertJsonCount(2)->assertJsonPath('0.name','Inside Dhaka')->assertJsonPath('0.is_custom',true)->json();
        $this->getJson('/api/seller/delivery-rates')->assertOk()->assertJsonPath('1.name','Custom regional delivery');
        $this->getJson('/api/shipping-methods')->assertOk()->assertJsonCount(1);
        $payload=['items'=>[['product_id'=>$product->id,'quantity'=>1]],'payment_method_id'=>PaymentMethod::firstOrFail()->id,'shipping_method_id'=>ShippingMethod::whereNull('seller_id')->firstOrFail()->id];
        $customerToken=$customer->createToken('test',['customer:basic'])->plainTextToken;
        $this->withToken($customerToken)->postJson('/api/customers/orders/preview',$payload)->assertOk()->assertJsonPath('shipping_charge',31)->assertJsonCount(2,'shipping_groups.0.shipping_options');
        $selected=$payload+['store_shipping_methods'=>[(string)$seller->id=>$rates[1]['shipping_method_id']]];
        $this->postJson('/api/customers/orders/preview',$selected)->assertOk()->assertJsonPath('shipping_charge',57)->assertJsonPath('shipping_groups.0.shipping_method_name','Custom regional delivery');
        $order=$this->postJson('/api/customers/orders',$selected+['shipping_name'=>'Customer','shipping_phone'=>'01700000000','shipping_address'=>'Dhaka'])->assertCreated()->assertJsonPath('order.shipping_charge','57.00')->json('order');
        $this->withToken($token)->postJson('/api/seller/delivery-rates',['options'=>[['shipping_method_id'=>$rates[0]['shipping_method_id'],'name'=>'Inside Dhaka','charge'=>33]]])->assertOk()->assertJsonCount(1);
        $this->assertDatabaseHas('shipping_methods',['id'=>$rates[1]['shipping_method_id'],'is_active'=>false]);
        $this->assertDatabaseHas('order_store_groups',['order_id'=>$order['id'],'shipping_method_id'=>$rates[1]['shipping_method_id'],'shipping_method_name'=>'Custom regional delivery','shipping_charge'=>57]);
        $this->withToken($customerToken)->postJson('/api/customers/orders/preview',$selected)->assertUnprocessable();
        $foreign=Seller::create(['seller_name'=>'Other','email'=>'delivery-other@example.test','store_name'=>'Other Store','store_slug'=>'delivery-other','kyc_type'=>'nid','kyc_number'=>'other','kyc_document_url'=>'https://example.test/id','product_category'=>'Clothing','status'=>'approved','is_active'=>true,'password'=>'password123']);
        $foreignMethod=ShippingMethod::create(['seller_id'=>$foreign->id,'name'=>'Private delivery','code'=>'private_delivery','charge'=>1,'is_active'=>true,'currency'=>'BDT']);
        $this->withToken($token)->postJson('/api/seller/delivery-rates',['options'=>[['shipping_method_id'=>$foreignMethod->id,'name'=>'Stolen option','charge'=>1]]])->assertForbidden();
        $this->postJson('/api/seller/delivery-rates',['rates'=>[['shipping_method_id'=>$foreignMethod->id,'charge'=>1]]])->assertForbidden();
        $this->withToken($customerToken)->postJson('/api/customers/orders/preview',$payload+['store_shipping_methods'=>[(string)$seller->id=>$foreignMethod->id]])->assertUnprocessable();
        $this->postJson('/api/customers/orders/preview',array_replace($payload,['shipping_method_id'=>$foreignMethod->id]))->assertUnprocessable();
    }

    public function test_house_delivery_options_do_not_change_other_store_defaults(): void
    {
        [$customer,$houseProduct]=$this->checkoutFixtures(3);
        $seller=Seller::create(['seller_name'=>'Store owner','email'=>'house-delivery-other@example.test','store_name'=>'Other Store','store_slug'=>'house-delivery-other','kyc_type'=>'nid','kyc_number'=>'other','kyc_document_url'=>'https://example.test/id','product_category'=>'Clothing','status'=>'approved','is_active'=>true,'password'=>'password123']);
        $other=Product::create(['seller_id'=>$seller->id,'category_id'=>$houseProduct->category_id,'name'=>'Other product','slug'=>'house-delivery-other-product','product_type'=>'simple','status'=>'active','default_selling_price'=>100]);
        ProductLot::create(['product_id'=>$other->id,'lot_number'=>'HOUSE-DELIVERY-OTHER','buying_price'=>50,'selling_price'=>100,'quantity'=>3,'quantity_remaining'=>3]);
        $admin=Admin::create(['name'=>'Super','email'=>'house-delivery-admin@example.test','password'=>'password123','role'=>'super_admin']);
        $this->withToken($admin->createToken('test',['admin:basic','admin:shipping-methods'])->plainTextToken);
        $this->getJson('/api/admin/platform-delivery-rates')->assertOk()->assertJsonCount(1,'rates');
        $saved=$this->postJson('/api/admin/platform-delivery-rates',['options'=>[['name'=>'House priority','charge'=>17],['name'=>'House collection','charge'=>0]]])->assertOk()->assertJsonCount(2,'rates')->json('rates');
        $this->getJson('/api/shipping-methods')->assertOk()->assertJsonCount(1);
        $this->assertDatabaseHas('shipping_methods',['seller_id'=>null,'is_store_option'=>false,'charge'=>20]);
        $this->postJson('/api/admin/shipping-methods/'.$saved[0]['shipping_method_id'],['charge'=>1])->assertNotFound();
        $payload=['items'=>[['product_id'=>$houseProduct->id,'quantity'=>1],['product_id'=>$other->id,'quantity'=>1]],'payment_method_id'=>PaymentMethod::firstOrFail()->id,'shipping_method_id'=>ShippingMethod::whereNull('seller_id')->where('is_store_option',false)->firstOrFail()->id];
        $this->withToken($customer->createToken('test',['customer:basic'])->plainTextToken)->postJson('/api/customers/orders/preview',$payload)->assertOk()->assertJsonPath('shipping_charge',37)->assertJsonPath('shipping_groups.0.shipping_method_name','House priority')->assertJsonPath('shipping_groups.1.shipping_charge',20);
        $this->postJson('/api/customers/orders/preview',$payload+['store_shipping_methods'=>[(string)$seller->id=>$saved[0]['shipping_method_id']]])->assertUnprocessable();
        $this->withToken($seller->createToken('test',['seller:basic'])->plainTextToken)->postJson('/api/seller/delivery-rates',['options'=>[['shipping_method_id'=>$saved[0]['shipping_method_id'],'name'=>'Stolen house option','charge'=>1]]])->assertForbidden();
        $regular=Admin::create(['name'=>'Regular','email'=>'house-delivery-regular@example.test','password'=>'password123','role'=>'admin']);
        $this->withToken($regular->createToken('test',['admin:basic'])->plainTextToken)->postJson('/api/admin/platform-delivery-rates',['options'=>[['name'=>'Unauthorized change','charge'=>1]]])->assertForbidden();
    }

    private function checkoutFixtures(int $quantity, bool $sellerOwned = false): array
    {
        $customer = Customer::create([
            'name' => 'Customer',
            'email' => 'customer@example.test',
            'password' => 'password123',
            'email_verified_at' => now(),
        ]);
        $category = ProductCategory::create(['name' => 'Category', 'slug' => 'category']);
        $seller = $sellerOwned ? Seller::create([
            'seller_name' => 'Seller',
            'email' => 'seller@example.test',
            'store_name' => 'Seller Store',
            'store_slug' => 'seller-store',
            'kyc_type' => 'nid',
            'kyc_number' => '123456',
            'kyc_document_url' => 'https://example.test/kyc',
            'product_category' => 'Category',
            'status' => 'approved',
            'is_active' => true,
            'password' => 'password123',
        ]) : null;
        $product = Product::create([
            'seller_id' => $seller?->id,
            'category_id' => $category->id,
            'name' => 'Product',
            'slug' => 'product',
            'product_type' => 'simple',
            'status' => 'active',
            'default_selling_price' => 100,
            'thumbnail' => 'https://ucarecdn.com/product/-/preview/',
            'gallery' => ['https://ucarecdn.com/gallery/-/preview/'],
        ]);
        ProductLot::create([
            'product_id' => $product->id,
            'lot_number' => 'LOT-1',
            'buying_price' => 50,
            'selling_price' => 100,
            'quantity' => $quantity,
            'quantity_remaining' => $quantity,
        ]);
        PaymentMethod::create(['name' => 'COD', 'code' => 'cod', 'is_active' => true]);
        ShippingMethod::create(['name' => 'Standard', 'code' => 'standard', 'charge' => 20, 'currency' => 'BDT', 'is_active' => true]);

        return [$customer, $product->fresh('seller')];
    }

    private function placeOrder(Customer $customer, Product $product, int $quantity, ?string $couponCode = null)
    {
        $token = $customer->createToken('test', ['customer:basic'])->plainTextToken;

        return $this->withToken($token)->postJson('/api/customers/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            'payment_method_id' => PaymentMethod::firstOrFail()->id,
            'shipping_method_id' => ShippingMethod::firstOrFail()->id,
            'coupon_code' => $couponCode,
            'shipping_name' => 'Customer',
            'shipping_phone' => '01700000000',
            'shipping_address' => 'Dhaka',
        ]);
    }
}
