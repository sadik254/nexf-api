<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLot;
use App\Models\Seller;
use App\Models\ShippingMethod;
use App\Mail\OrderNotificationMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_snapshots_images_and_prevents_overselling(): void
    {
        [$customer, $product] = $this->checkoutFixtures(2);

        $response = $this->placeOrder($customer, $product, 2);
        $response->assertCreated()
            ->assertJsonPath('order.items.0.product_thumbnail', 'https://ucarecdn.com/product/-/preview/')
            ->assertJsonPath('order.items.0.product_image_variants.thumbnail.thumb', 'https://ucarecdn.com/product/-/scale_crop/256x256/smart/-/format/auto');

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
            ->postJson("/api/seller/orders/{$order['id']}/items/{$itemId}/fulfillment", ['status' => 'shipped'])
            ->assertForbidden();

        $admin = Admin::create(['name' => 'Shipping Admin', 'email' => 'shipping-admin@example.test', 'password' => 'password123', 'role' => 'super_admin']);
        $adminToken = $admin->createToken('test', ['admin:orders'])->plainTextToken;
        $this->withToken($adminToken)
            ->postJson('/api/admin/orders/bulk-ship', ['order_item_ids' => [$itemId]])
            ->assertOk()
            ->assertJsonPath('successful_item_ids.0', $itemId);

        $this->withToken($adminToken)
            ->postJson("/api/admin/orders/{$order['id']}/items/{$itemId}/fulfillment", ['status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('order.status', 'delivered');
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
