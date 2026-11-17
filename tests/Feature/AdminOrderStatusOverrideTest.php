<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStoreGroup;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLot;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminOrderStatusOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_jump_a_nexf_parcel_to_delivered_without_booking_steadfast(): void
    {
        [$admin, $order, $group, $item] = $this->order('confirmed');
        Http::fake();
        Mail::fake();

        $this->as($admin)->postJson("/api/admin/orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertOk()->assertJsonPath('order.status', 'delivered');

        $this->assertSame('delivered', $item->fresh()->fulfillment_status);
        $this->assertNotNull($item->fresh()->delivered_at);
        $this->assertNull($group->fresh()->courier_consignment_id);
        Http::assertNothingSent();
    }

    public function test_staff_can_take_a_shipped_nexf_parcel_back_and_clear_its_local_courier_record(): void
    {
        [$admin, $order, $group, $item] = $this->order('shipped', [
            'courier_provider' => 'steadfast', 'courier_consignment_id' => '123', 'tracking_number' => 'SFR-123',
            'courier_tracking_url' => 'https://steadfast.test/tl/123', 'courier_invoice' => 'INV-123',
            'courier_status' => 'in_review', 'shipped_at' => now(),
        ]);
        $item->update([
            'courier_provider' => 'steadfast', 'courier_consignment_id' => '123', 'tracking_number' => 'SFR-123',
            'courier_tracking_url' => 'https://steadfast.test/tl/123', 'courier_invoice' => 'INV-123',
            'courier_status' => 'in_review', 'shipped_at' => now(),
        ]);

        $this->as($admin)->postJson("/api/admin/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();

        $this->assertSame('confirmed', $item->fresh()->fulfillment_status);
        $this->assertNull($item->fresh()->courier_consignment_id);
        $this->assertNull($item->fresh()->tracking_number);
        $this->assertNull($item->fresh()->shipped_at);
        $this->assertNull($group->fresh()->courier_consignment_id);
        $this->assertNull($group->fresh()->tracking_number);
        $this->assertNull($group->fresh()->shipped_at);
    }

    public function test_reopening_a_cancelled_nexf_order_retakes_stock_and_reverses_its_cancellation_credit(): void
    {
        [$admin, $order, $group, $item, $product, $customer] = $this->order('cancelled', [], true);
        CustomerWalletTransaction::create([
            'customer_id' => $customer->id, 'order_id' => $order->id, 'type' => 'credit', 'kind' => 'cancellation_credit',
            'bucket' => 'reward', 'amount' => 100, 'source_key' => "order:{$order->id}:cancellation:reward", 'description' => 'Cancellation credit',
        ]);

        $this->assertSame(5, (int) ProductLot::where('product_id', $product->id)->value('quantity_remaining'));
        $this->as($admin)->postJson("/api/admin/orders/{$order->id}/status", ['status' => 'pending'])->assertOk();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->cancelled_at);
        $this->assertSame('pending', $item->fresh()->fulfillment_status);
        $this->assertSame(4, (int) ProductLot::where('product_id', $product->id)->value('quantity_remaining'));
        $this->assertDatabaseHas('customer_wallet_transactions', [
            'customer_id' => $customer->id, 'order_id' => $order->id, 'type' => 'debit', 'kind' => 'cancellation_reversal',
            'source_key' => "order:{$order->id}:cancellation:reward:reversal",
        ]);
    }

    public function test_status_override_leaves_a_seller_parcel_alone(): void
    {
        [$admin, $order, $group, $item] = $this->order('confirmed');
        $seller = Seller::create(['seller_name' => 'Store owner', 'store_name' => 'Store', 'store_slug' => 'store', 'email' => 'store@example.test', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $sellerGroup = OrderStoreGroup::create(['order_id' => $order->id, 'number' => "{$order->order_number}-STORE", 'seller_id' => $seller->id, 'ships_by' => 'seller', 'status' => 'shipped', 'store_name' => 'Store', 'subtotal' => 10, 'shipping_charge' => 0, 'discount_share' => 0]);
        $sellerItem = OrderItem::create(['order_id' => $order->id, 'store_group_id' => $sellerGroup->id, 'seller_id' => $seller->id, 'product_name' => 'Seller item', 'quantity' => 1, 'unit_selling_price' => 10, 'unit_buying_price' => 5, 'line_subtotal' => 10, 'line_cost' => 5, 'line_profit' => 5, 'fulfillment_status' => 'shipped', 'tracking_number' => 'SELLER-1']);

        $this->as($admin)->postJson("/api/admin/orders/{$order->id}/status", ['status' => 'pending'])->assertOk();

        $this->assertSame('pending', $item->fresh()->fulfillment_status);
        $this->assertSame('shipped', $sellerItem->fresh()->fulfillment_status);
        $this->assertSame('SELLER-1', $sellerItem->fresh()->tracking_number);
    }

    private function order(string $status, array $groupAttributes = [], bool $cancelled = false): array
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => "admin-{$status}-{$cancelled}@example.test", 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Customer', 'email' => "customer-{$status}-{$cancelled}@example.test", 'password' => 'password123']);
        $category = ProductCategory::create(['name' => "Category {$status} {$cancelled}", 'slug' => "category-{$status}-{$cancelled}"]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'House item', 'slug' => "house-item-{$status}-{$cancelled}", 'product_type' => 'simple', 'status' => 'active', 'default_selling_price' => 100]);
        ProductLot::create(['product_id' => $product->id, 'lot_number' => "LOT-{$status}-{$cancelled}", 'buying_price' => 50, 'selling_price' => 100, 'quantity' => 5, 'quantity_remaining' => 5]);
        $order = Order::create(['customer_id' => $customer->id, 'order_number' => "OVERRIDE-{$status}-{$cancelled}", 'status' => $cancelled ? 'cancelled' : $status, 'subtotal' => 100, 'total' => 100, 'shipping_name' => 'Customer', 'shipping_phone' => '01700000000', 'shipping_address' => 'Dhaka', 'cancelled_at' => $cancelled ? now() : null, 'cancelled_by' => $cancelled ? 'admin' : null]);
        $group = OrderStoreGroup::create(array_merge(['order_id' => $order->id, 'number' => "{$order->order_number}-NEXF", 'ships_by' => 'nexf', 'status' => $status, 'store_name' => 'NEXF', 'subtotal' => 100, 'shipping_charge' => 0, 'discount_share' => 0], $groupAttributes));
        $item = OrderItem::create(['order_id' => $order->id, 'store_group_id' => $group->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 1, 'unit_selling_price' => 100, 'unit_buying_price' => 50, 'line_subtotal' => 100, 'line_cost' => 50, 'line_profit' => 50, 'lot_allocations' => [['lot_id' => ProductLot::where('product_id', $product->id)->value('id'), 'quantity' => 1]], 'fulfillment_status' => $cancelled ? 'cancelled' : $status]);

        return [$admin, $order, $group, $item, $product, $customer];
    }

    private function as(Admin $admin): static
    {
        return $this->withToken($admin->createToken('test', ['admin:orders'])->plainTextToken);
    }
}
