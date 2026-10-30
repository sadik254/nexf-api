<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStoreGroup;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLot;
use App\Models\Seller;
use App\Models\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Handoff items 42-44: going back a step, and cancelling after a parcel has shipped. */
class ParcelStepBackAndCancelTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_take_a_partly_confirmed_order_back_to_processing(): void
    {
        [$customer, $house, $sellerProduct, , $admin] = $this->fixtures();
        $order = $this->place($customer, [[$house, 1], [$sellerProduct, 1]])->json('order');
        $token = $admin->createToken('t', ['admin:orders'])->plainTextToken;
        $houseLine = OrderItem::query()->where('order_id', $order['id'])->whereNull('seller_id')->firstOrFail();
        $this->withToken($token)->postJson("/api/admin/orders/{$order['id']}/items/{$houseLine->id}/fulfillment", ['status' => 'confirmed'])->assertOk();
        $this->assertSame('pending', Order::findOrFail($order['id'])->status);

        $this->withToken($token)->postJson("/api/admin/orders/{$order['id']}", ['status' => 'pending'])->assertOk();
        $this->assertSame('pending', $houseLine->fresh()->fulfillment_status);
        $this->assertNull($houseLine->fresh()->confirmed_at);
    }

    public function test_a_store_steps_its_parcel_back_until_it_ships(): void
    {
        [$customer, $house, $sellerProduct, $seller] = $this->fixtures();
        $order = $this->place($customer, [[$house, 1], [$sellerProduct, 1]])->json('order');
        $parcel = OrderStoreGroup::query()->where('order_id', $order['id'])->where('ships_by', 'seller')->firstOrFail();
        $token = $seller->createToken('t', ['seller:basic'])->plainTextToken;
        $url = "/api/seller/orders/{$order['id']}/groups/{$parcel->id}/fulfillment";

        $this->withToken($token)->postJson($url, ['status' => 'confirmed'])->assertOk();
        $this->withToken($token)->postJson($url, ['status' => 'packed'])->assertOk();
        $this->withToken($token)->postJson($url, ['status' => 'confirmed'])->assertOk();
        $this->assertSame('confirmed', $parcel->fresh()->status);
        $this->assertNull($parcel->fresh()->packed_at);
        $this->withToken($token)->postJson($url, ['status' => 'pending'])->assertOk();
        $this->assertSame('pending', $parcel->fresh()->status);
        $this->withToken($token)->postJson($url, ['status' => 'pending'])->assertUnprocessable();

        foreach (['confirmed', 'packed'] as $step) $this->withToken($token)->postJson($url, ['status' => $step])->assertOk();
        $this->withToken($token)->postJson($url, ['status' => 'shipped', 'courier_provider' => 'pathao', 'tracking_number' => 'PTH-1'])->assertOk();
        $this->withToken($token)->postJson($url, ['status' => 'packed'])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_staff_cancel_after_a_store_shipped_and_the_shipped_lines_wait_for_the_courier(): void
    {
        [$customer, $house, $sellerProduct, $seller, $admin] = $this->fixtures();
        $order = $this->place($customer, [[$house, 1], [$sellerProduct, 1]])->json('order');
        $this->shipStoreParcel($order['id'], $seller);
        $houseStock = (int) ProductLot::query()->where('product_id', $house->id)->value('quantity_remaining');

        $this->withToken($admin->createToken('t', ['admin:orders'])->plainTextToken)->postJson("/api/admin/orders/{$order['id']}/cancel")->assertOk();

        $this->assertSame('cancelled', Order::findOrFail($order['id'])->status);
        $this->assertSame('return_pending', OrderItem::query()->where('order_id', $order['id'])->where('seller_id', $seller->id)->value('fulfillment_status'));
        $this->assertSame('cancelled', OrderItem::query()->where('order_id', $order['id'])->whereNull('seller_id')->value('fulfillment_status'));
        $this->assertSame($houseStock + 1, (int) ProductLot::query()->where('product_id', $house->id)->value('quantity_remaining'));
    }

    public function test_a_delivered_line_goes_back_through_a_return_not_a_cancel(): void
    {
        [$customer, , $sellerProduct, $seller, $admin] = $this->fixtures();
        $order = $this->place($customer, [[$sellerProduct, 1]])->json('order');
        $url = $this->shipStoreParcel($order['id'], $seller);
        $this->withToken($seller->createToken('t', ['seller:basic'])->plainTextToken)->postJson($url, ['status' => 'delivered'])->assertOk();

        $this->withToken($admin->createToken('t', ['admin:orders'])->plainTextToken)->postJson("/api/admin/orders/{$order['id']}/cancel")
            ->assertUnprocessable()->assertJsonValidationErrors('order');
    }

    public function test_a_store_cancels_its_own_parcel_and_the_order_closes_when_nothing_is_left(): void
    {
        [$customer, $house, $sellerProduct, $seller, , $other] = $this->fixtures();
        $order = $this->place($customer, [[$house, 1], [$sellerProduct, 1]])->json('order');
        $parcel = OrderStoreGroup::query()->where('order_id', $order['id'])->where('ships_by', 'seller')->firstOrFail();
        $nexfParcel = OrderStoreGroup::query()->where('order_id', $order['id'])->where('ships_by', 'nexf')->firstOrFail();
        $token = $seller->createToken('t', ['seller:basic'])->plainTextToken;

        // Another store, or NEXF's parcel, is not this store's to cancel.
        $blocked = $this->withToken($other->createToken('t', ['seller:basic'])->plainTextToken)->postJson("/api/seller/orders/{$order['id']}/groups/{$parcel->id}/cancel")->status();
        $this->assertContains($blocked, [403, 404]);
        $blocked = $this->withToken($token)->postJson("/api/seller/orders/{$order['id']}/groups/{$nexfParcel->id}/cancel")->status();
        $this->assertContains($blocked, [403, 404]);

        $this->withToken($token)->postJson("/api/seller/orders/{$order['id']}/groups/{$parcel->id}/cancel")->assertOk();
        $this->assertSame('cancelled', $parcel->fresh()->status);
        $this->assertNotSame('cancelled', Order::findOrFail($order['id'])->status);

        // A store-only order closes as cancelled by the store.
        $solo = $this->place($customer, [[$sellerProduct, 1]])->json('order');
        $soloParcel = OrderStoreGroup::query()->where('order_id', $solo['id'])->firstOrFail();
        $this->withToken($token)->postJson("/api/seller/orders/{$solo['id']}/groups/{$soloParcel->id}/cancel")->assertOk();
        $closed = Order::findOrFail($solo['id']);
        $this->assertSame('cancelled', $closed->status);
        $this->assertSame('seller', $closed->cancelled_by);
    }

    public function test_a_cancelled_parcels_prepaid_share_goes_back_to_the_shoppers_balance_once(): void
    {
        [$customer, , $sellerProduct, $seller, , $other] = $this->fixtures();
        $otherProduct = Product::create(['seller_id' => $other->id, 'category_id' => $sellerProduct->category_id, 'name' => 'Other Cap', 'slug' => 'other-cap', 'product_type' => 'simple', 'status' => 'active', 'default_selling_price' => 300]);
        ProductLot::create(['product_id' => $otherProduct->id, 'lot_number' => 'cap', 'buying_price' => 50, 'selling_price' => 300, 'quantity' => 5, 'quantity_remaining' => 5]);
        $order = Order::findOrFail($this->place($customer, [[$sellerProduct, 1], [$otherProduct, 1]])->json('order.id'));
        $order->update(['wallet_amount' => 64]);
        $parcels = OrderStoreGroup::query()->where('order_id', $order->id)->get()->keyBy('seller_id');
        $first = $parcels[$seller->id];
        $share = (float) $first->fresh()->prepaid_amount;
        $this->assertGreaterThan(0, $share);
        $credits = fn () => (float) CustomerWalletTransaction::query()->where('customer_id', $customer->id)->where('kind', 'cancellation_credit')->sum('amount');

        $this->withToken($seller->createToken('t', ['seller:basic'])->plainTextToken)->postJson("/api/seller/orders/{$order->id}/groups/{$first->id}/cancel")->assertOk();
        $this->assertSame($share, $credits());
        // Cancelling again pays nothing more.
        $this->withToken($seller->createToken('t', ['seller:basic'])->plainTextToken)->postJson("/api/seller/orders/{$order->id}/groups/{$first->id}/cancel");
        $this->assertSame($share, $credits());

        // The last parcel closes the order: the rest of the Balance comes back, never more than was paid.
        $last = $parcels[$other->id];
        $this->withToken($other->createToken('t', ['seller:basic'])->plainTextToken)->postJson("/api/seller/orders/{$order->id}/groups/{$last->id}/cancel")->assertOk();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(64.0, $credits());
    }

    public function test_a_parcel_paid_by_bkash_comes_back_as_withdrawable_balance(): void
    {
        [$customer, , $sellerProduct, $seller, , $other] = $this->fixtures();
        $otherProduct = Product::create(['seller_id' => $other->id, 'category_id' => $sellerProduct->category_id, 'name' => 'Other Cap', 'slug' => 'other-cap', 'product_type' => 'simple', 'status' => 'active', 'default_selling_price' => 300]);
        ProductLot::create(['product_id' => $otherProduct->id, 'lot_number' => 'cap', 'buying_price' => 50, 'selling_price' => 300, 'quantity' => 5, 'quantity_remaining' => 5]);
        $order = Order::findOrFail($this->place($customer, [[$sellerProduct, 1], [$otherProduct, 1]])->json('order.id'));
        $order->update(['payment_method_code' => 'bkash', 'paid_amount' => 100, 'payment_received_at' => now()]);
        $parcel = OrderStoreGroup::query()->where('order_id', $order->id)->where('seller_id', $seller->id)->firstOrFail();
        $share = (float) $parcel->fresh()->prepaid_amount;

        $this->withToken($seller->createToken('t', ['seller:basic'])->plainTextToken)->postJson("/api/seller/orders/{$order->id}/groups/{$parcel->id}/cancel")->assertOk();

        $credit = CustomerWalletTransaction::query()->where('customer_id', $customer->id)->where('kind', 'cancellation_credit')->get();
        $this->assertSame($share, (float) $credit->sum('amount'));
        $this->assertSame(['withdrawable'], $credit->pluck('bucket')->unique()->values()->all());
    }

    /** Confirms, packs and ships the store's parcel with its own courier; returns the parcel's fulfilment URL. */
    private function shipStoreParcel(int $orderId, Seller $seller): string
    {
        $parcel = OrderStoreGroup::query()->where('order_id', $orderId)->where('ships_by', 'seller')->firstOrFail();
        $token = $seller->createToken('t', ['seller:basic'])->plainTextToken;
        $url = "/api/seller/orders/{$orderId}/groups/{$parcel->id}/fulfillment";
        foreach (['confirmed', 'packed'] as $step) $this->withToken($token)->postJson($url, ['status' => $step])->assertOk();
        $this->withToken($token)->postJson($url, ['status' => 'shipped', 'courier_provider' => 'pathao', 'tracking_number' => 'PTH-1'])->assertOk();
        return $url;
    }

    private function fixtures(): array
    {
        $customer = Customer::create(['name' => 'Customer', 'email' => 'customer@example.test', 'password' => 'password123', 'email_verified_at' => now()]);
        $admin = Admin::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password123', 'role' => 'super_admin']);
        $makeSeller = fn (string $name, string $code) => Seller::create(['seller_name' => $name, 'email' => strtolower($name) . '@example.test', 'store_name' => "{$name} Store", 'store_slug' => strtolower($name), 'kyc_type' => 'nid', 'kyc_number' => $code, 'kyc_document_url' => 'https://example.test/kyc', 'product_category' => 'General', 'status' => 'approved', 'is_active' => true, 'password' => 'password123', 'sku_prefix' => $code, 'commission_rate' => 10, 'address_line' => 'House 1', 'address_area' => 'Banani', 'address_district' => 'Dhaka']);
        $seller = $makeSeller('Sadik', 'SADK');
        $other = $makeSeller('Other', 'OTHR');
        $category = ProductCategory::create(['name' => 'Category', 'slug' => 'category']);
        $makeProduct = function (?Seller $owner, string $name) use ($category) {
            $product = Product::create(['seller_id' => $owner?->id, 'category_id' => $category->id, 'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'product_type' => 'simple', 'status' => 'active', 'default_selling_price' => $owner ? 300 : 100]);
            ProductLot::create(['product_id' => $product->id, 'lot_number' => $name, 'buying_price' => 50, 'selling_price' => $owner ? 300 : 100, 'quantity' => 5, 'quantity_remaining' => 5]);
            return $product;
        };
        ShippingMethod::create(['name' => 'Inside Dhaka', 'code' => 'inside_dhaka', 'charge' => 20, 'currency' => 'BDT', 'is_active' => true]);
        PaymentMethod::firstOrCreate(['code' => 'cod'], ['name' => 'Cash on Delivery', 'is_active' => true]);
        return [$customer, $makeProduct(null, 'House Shirt'), $makeProduct($seller, 'Seller Jacket'), $seller, $admin, $other];
    }

    private function place(Customer $customer, array $lines)
    {
        return $this->withToken($customer->createToken('t', ['customer:basic'])->plainTextToken)->postJson('/api/customers/orders', [
            'items' => array_map(fn ($line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
            'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'),
            'shipping_method_id' => ShippingMethod::value('id'),
            'shipping_name' => 'Customer', 'shipping_phone' => '01700000000', 'shipping_address' => 'Dhaka',
        ]);
    }
}
