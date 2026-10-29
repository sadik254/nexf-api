<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\OrderStoreGroup;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLot;
use App\Models\ReturnRequest;
use App\Models\Seller;
use App\Models\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** One customer order, one sub-order per store; stores ship their own parcels. */
class SubOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_mixed_cart_gets_one_numbered_sub_order_per_store_with_its_own_cod(): void
    {
        [$customer, $house, $sellerProduct] = $this->fixtures();
        $order = $this->place($customer, [[$house, 2], [$sellerProduct, 1]])->assertCreated()->json('order');

        $this->assertMatchesRegularExpression('/^NEXF-\d{5}$/', $order['order_number']);
        $groups = OrderStoreGroup::query()->where('order_id', $order['id'])->get()->keyBy('ships_by');
        $this->assertSame($order['order_number'] . '-NEXF', $groups['nexf']->number);
        $this->assertSame($order['order_number'] . '-SADK', $groups['seller']->number);
        // Each parcel collects its own lines plus its own delivery charge.
        $this->assertSame('220.00', $groups['nexf']->cod_amount);
        $this->assertSame('320.00', $groups['seller']->cod_amount);
        $this->assertSame(2, (int) $groups['nexf']->items()->sum('quantity'));
        $this->assertSame(1, $groups['seller']->items()->count());
    }

    public function test_a_payment_recorded_by_staff_is_split_across_unshipped_parcels(): void
    {
        [$customer, $house, $sellerProduct, , $admin] = $this->fixtures();
        $order = $this->place($customer, [[$house, 2], [$sellerProduct, 1]])->json('order');
        $this->withToken($admin->createToken('t', ['admin:orders'])->plainTextToken)
            ->postJson("/api/admin/orders/{$order['id']}", ['paid_amount' => 270])->assertOk();

        $groups = OrderStoreGroup::query()->where('order_id', $order['id'])->get()->keyBy('ships_by');
        $this->assertSame('110.00', $groups['nexf']->prepaid_amount);
        $this->assertSame('110.00', $groups['nexf']->cod_amount);
        $this->assertSame('160.00', $groups['seller']->prepaid_amount);
        $this->assertSame('160.00', $groups['seller']->cod_amount);
    }

    public function test_packing_books_only_the_nexf_parcel_and_the_store_ships_its_own(): void
    {
        config(['services.steadfast.api_key' => 'k', 'services.steadfast.secret_key' => 's', 'services.steadfast.base_url' => 'https://steadfast.test/api/v1']);
        Http::fake(fn ($request) => Http::response(['data' => collect(json_decode($request['data'], true))->map(fn ($p) => ['invoice' => $p['invoice'], 'consignment_id' => 7, 'tracking_code' => 'SF-7', 'status' => 'in_review'])->all()]));
        [$customer, $house, $sellerProduct, $seller, $admin, $other] = $this->fixtures();
        $order = $this->place($customer, [[$house, 2], [$sellerProduct, 1]])->json('order');
        $adminToken = $admin->createToken('t', ['admin:orders'])->plainTextToken;
        $groups = OrderStoreGroup::query()->where('order_id', $order['id'])->get()->keyBy('ships_by');

        $this->withToken($adminToken)->postJson("/api/admin/orders/{$order['id']}/groups/{$groups['nexf']->id}/fulfillment", ['status' => 'confirmed'])->assertOk();
        $this->withToken($adminToken)->postJson("/api/admin/orders/{$order['id']}", ['packed' => true])->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($groups) {
            $parcels = json_decode($request['data'], true);
            return count($parcels) === 1 && $parcels[0]['invoice'] === $groups['nexf']->number
                && $parcels[0]['cod_amount'] == 220 && $parcels[0]['total_lot'] === 2
                && $parcels[0]['item_description'] === 'House Shirt ×2';
        });
        $this->assertSame('shipped', $groups['nexf']->fresh()->status);
        $this->assertSame('SF-7', $groups['nexf']->fresh()->tracking_number);
        $this->assertSame('pending', $groups['seller']->fresh()->status);

        // Another store can't touch this parcel; the owning store ships it with its own courier.
        $this->withToken($other->createToken('t', ['seller:basic'])->plainTextToken)
            ->postJson("/api/seller/orders/{$order['id']}/groups/{$groups['seller']->id}/fulfillment", ['status' => 'confirmed'])->assertNotFound();
        $sellerToken = $seller->createToken('t', ['seller:basic'])->plainTextToken;
        $url = "/api/seller/orders/{$order['id']}/groups/{$groups['seller']->id}/fulfillment";
        $this->withToken($sellerToken)->postJson($url, ['status' => 'confirmed'])->assertOk();
        $this->withToken($sellerToken)->postJson($url, ['status' => 'packed'])->assertOk();
        $this->withToken($sellerToken)->postJson($url, ['status' => 'shipped', 'courier_provider' => 'Pathao Courier', 'tracking_number' => 'PTH-1', 'tracking_url' => 'https://pathao.test/t/PTH-1'])
            ->assertOk()->assertJsonPath('order.items.0.tracking_number', 'PTH-1')
            ->assertJsonPath('order.store_groups.0.courier_provider', 'Pathao Courier');
        Http::assertSentCount(1);
        $this->withToken($sellerToken)->postJson($url, ['status' => 'delivered'])->assertOk();
        $this->assertSame('delivered', $groups['seller']->fresh()->status);

        // The store collected ৳320 COD itself; NEXF holds nothing for it, so it owes the commission.
        $this->travel(8)->days();
        $ledger = $this->withToken($sellerToken)->getJson('/api/seller/payouts')->assertOk()->json();
        $this->assertEqualsCanonicalizing(['order', 'delivery', 'cod'], array_column($ledger['earnings'], 'kind'));
        $this->assertEquals(0, $ledger['balance']['available']);
        $this->assertEquals(30, $ledger['balance']['owed']);
    }

    public function test_a_store_with_its_own_steadfast_keys_gets_its_parcel_booked_on_its_account(): void
    {
        config(['services.steadfast.api_key' => 'nexf-key', 'services.steadfast.secret_key' => 'nexf-secret', 'services.steadfast.base_url' => 'https://steadfast.test/api/v1', 'services.steadfast.webhook_token' => 'nexf-hook']);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'get_balance')) {
                return $request->header('Api-Key')[0] === 'store-key' ? Http::response(['status' => 200, 'current_balance' => 0]) : Http::response(['status' => 401], 401);
            }
            return Http::response(['status' => 200, 'consignment' => ['consignment_id' => 99, 'invoice' => $request['invoice'], 'tracking_code' => 'SF-STORE', 'tracking_link' => 'https://steadfast.test/t/SF-STORE', 'status' => 'in_review']]);
        });
        [$customer, , $sellerProduct, $seller] = $this->fixtures();
        $sellerToken = $seller->createToken('t', ['seller:basic'])->plainTextToken;

        $this->withToken($sellerToken)->postJson('/api/seller/courier/steadfast', ['api_key' => 'wrong', 'secret_key' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('api_key');
        $state = $this->withToken($sellerToken)->postJson('/api/seller/courier/steadfast', ['api_key' => 'store-key', 'secret_key' => 'store-secret'])
            ->assertOk()->assertJsonPath('steadfast.connected', true)->assertJsonPath('steadfast.api_key_hint', '••••-key')->json('steadfast');
        $this->assertStringNotContainsString('store-secret', json_encode($seller->fresh()));

        $order = $this->place($customer, [[$sellerProduct, 1]])->json('order');
        $group = OrderStoreGroup::query()->where('order_id', $order['id'])->sole();
        $url = "/api/seller/orders/{$order['id']}/groups/{$group->id}/fulfillment";
        $this->withToken($sellerToken)->postJson($url, ['status' => 'confirmed'])->assertOk();
        $this->withToken($sellerToken)->postJson($url, ['status' => 'packed'])->assertOk()->assertJsonPath('order.status', 'shipped');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/create_order') && $r->header('Api-Key')[0] === 'store-key' && $r['invoice'] === $group->number && $r['cod_amount'] == 320);
        $this->assertSame('SF-STORE', $group->fresh()->tracking_number);

        // NEXF's token can't move a store's parcel; the store's own token can.
        $hook = ['notification_type' => 'delivery_status', 'consignment_id' => 99, 'invoice' => $group->number, 'status' => 'delivered'];
        $this->withToken('nexf-hook')->postJson('/api/webhooks/steadfast', $hook)->assertOk();
        $this->assertSame('shipped', $group->fresh()->status);
        $this->withToken($state['webhook_token'])->postJson('/api/webhooks/steadfast', $hook + ['tracking_message' => 'again'])->assertOk();
        $this->assertSame('delivered', $group->fresh()->status);

        $this->withToken($sellerToken)->postJson('/api/seller/courier/steadfast/delete')->assertOk()->assertJsonPath('steadfast.connected', false);
    }

    public function test_a_store_in_the_house_delivery_group_ships_with_nexf(): void
    {
        [$customer, $house, $sellerProduct, $seller, $admin] = $this->fixtures();
        // The shared delivery group alone decides who ships: this one ships from the house store.
        \App\Models\DeliveryGroup::create(['name' => 'House stores', 'seller_ids' => [$seller->id], 'includes_house' => true, 'ships_from_seller_id' => null]);

        $order = $this->place($customer, [[$sellerProduct, 1]])->assertCreated()->json('order');
        $group = OrderStoreGroup::query()->where('order_id', $order['id'])->sole();
        $this->assertSame('nexf', $group->ships_by);
        $this->assertSame($order['order_number'] . '-NEXF', $group->number);

        // The store can confirm its line but not pack or ship NEXF's parcel.
        $sellerToken = $seller->createToken('t', ['seller:basic'])->plainTextToken;
        $itemId = $group->items()->value('id');
        $this->withToken($sellerToken)->postJson("/api/seller/orders/{$order['id']}/items/{$itemId}/fulfillment", ['status' => 'confirmed'])->assertOk();
        $this->withToken($sellerToken)->postJson("/api/seller/orders/{$order['id']}/items/{$itemId}/fulfillment", ['status' => 'packed'])->assertUnprocessable();

        // Returns go back to NEXF, which shipped it, with no store deadline.
        $group->items()->update(['fulfillment_status' => 'delivered', 'delivered_at' => now()]);
        $this->withToken($customer->createToken('t', ['customer:basic'])->plainTextToken)->postJson('/api/customers/return-requests', [
            'order_item_id' => $itemId, 'type' => 'refund', 'quantity' => 1, 'reason' => 'Size',
        ])->assertCreated()->assertJsonPath('respond_by', null)->assertJsonPath('return_to_name', fn ($v) => $v !== 'Sadik Store');

        // NEXF collects the COD, so the store owes nothing.
        $this->travel(8)->days();
        $ledger = $this->withToken($sellerToken)->getJson('/api/seller/payouts')->assertOk()->json();
        $this->assertSame(['order'], array_column($ledger['earnings'], 'kind'));
        $this->assertEquals(270, $ledger['balance']['available']);
    }

    public function test_returns_route_to_the_shipping_store_and_can_be_escalated(): void
    {
        [$customer, , $sellerProduct, $seller, $admin] = $this->fixtures();
        $order = $this->place($customer, [[$sellerProduct, 1]])->json('order');
        $group = OrderStoreGroup::query()->where('order_id', $order['id'])->sole();
        $group->items()->update(['fulfillment_status' => 'delivered', 'delivered_at' => now()]);
        $customerToken = $customer->createToken('t', ['customer:basic'])->plainTextToken;

        $return = $this->withToken($customerToken)->postJson('/api/customers/return-requests', [
            'order_item_id' => $group->items()->value('id'), 'type' => 'refund', 'quantity' => 1, 'reason' => 'Wrong size',
        ])->assertCreated()->assertJsonPath('return_to_name', 'Sadik Store')->assertJsonPath('return_to_address', 'House 1, Banani, Dhaka')->json();

        // Not yet: the store still has 48 hours.
        $this->withToken($customerToken)->postJson("/api/customers/return-requests/{$return['id']}/escalate")->assertUnprocessable();
        $this->travel(49)->hours();
        $this->withToken($customerToken)->postJson("/api/customers/return-requests/{$return['id']}/escalate")->assertOk();
        $this->assertNotNull(ReturnRequest::findOrFail($return['id'])->escalated_at);

        // The store turned it down, NEXF overrules.
        ReturnRequest::whereKey($return['id'])->update(['status' => 'rejected']);
        $this->withToken($admin->createToken('t', ['admin:returns'])->plainTextToken)
            ->postJson("/api/admin/return-requests/{$return['id']}", ['status' => 'approved'])->assertOk()->assertJsonPath('status', 'approved');
    }

    public function test_an_exchange_stays_with_the_store_that_sold_the_item(): void
    {
        [$customer, $house, $sellerProduct, , $admin] = $this->fixtures();
        $order = $this->place($customer, [[$sellerProduct, 1]])->json('order');
        $group = OrderStoreGroup::query()->where('order_id', $order['id'])->sole();
        $group->items()->update(['fulfillment_status' => 'delivered', 'delivered_at' => now()]);
        $return = $this->withToken($customer->createToken('t', ['customer:basic'])->plainTextToken)->postJson('/api/customers/return-requests', [
            'order_item_id' => $group->items()->value('id'), 'type' => 'exchange', 'quantity' => 1, 'reason' => 'Size',
        ])->json();

        $this->withToken($admin->createToken('t', ['admin:orders'])->plainTextToken)->postJson('/api/admin/orders', [
            'customer_id' => $customer->id, 'exchange_for' => $return['id'],
            'items' => [['product_id' => $house->id, 'quantity' => 1]],
            'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'shipping_method_id' => ShippingMethod::value('id'),
            'shipping_name' => 'Customer', 'shipping_phone' => '01700000000', 'shipping_address' => 'Dhaka',
        ])->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_a_store_ticket_can_be_passed_to_nexf(): void
    {
        [$customer, , $sellerProduct, $seller] = $this->fixtures();
        $order = $this->place($customer, [[$sellerProduct, 1]])->json('order');
        $token = $customer->createToken('t', ['customer:basic'])->plainTextToken;
        $ticket = $this->withToken($token)->postJson('/api/customers/support-tickets', [
            'order_id' => $order['id'], 'seller_id' => $seller->id, 'category' => 'Late', 'subject' => 'Where is it?', 'body' => 'Still waiting.',
        ])->assertCreated()->json();
        $this->assertNotNull($ticket['respond_by']);

        $this->withToken($token)->postJson("/api/customers/support-tickets/{$ticket['id']}/escalate")->assertOk()->assertJsonPath('escalated_at', fn ($v) => !empty($v));
        $this->withToken($token)->postJson("/api/customers/support-tickets/{$ticket['id']}/escalate")->assertUnprocessable();
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
