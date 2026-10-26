<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\SteadfastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SteadfastLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_courier_responses_and_webhook_events(): void
    {
        config(['services.steadfast.api_key' => 'key', 'services.steadfast.secret_key' => 'secret', 'services.steadfast.base_url' => 'https://steadfast.test/api/v1', 'services.steadfast.webhook_token' => 'webhook-token']);
        $order = Order::create(['order_number' => 'LOG-ORDER', 'status' => 'packed', 'payment_status' => 'paid', 'subtotal' => 100, 'total' => 100, 'shipping_name' => 'Buyer', 'shipping_phone' => '01712345678', 'shipping_address' => 'Dhaka']);
        $item = OrderItem::create(['order_id' => $order->id, 'product_name' => 'Item', 'quantity' => 1, 'unit_selling_price' => 100, 'unit_buying_price' => 50, 'line_subtotal' => 100, 'line_cost' => 50, 'line_profit' => 50, 'fulfillment_status' => 'packed']);
        Http::fake(['steadfast.test/*' => Http::response(['status' => 200, 'consignment' => ['consignment_id' => 42, 'invoice' => 'LOG-ORDER-ITEM-'.$item->id, 'tracking_code' => 'TRACK-42', 'tracking_link' => 'https://steadfast.test/tl/log-link', 'status' => 'in_review']])]);
        Log::spy();

        $courier = app(SteadfastService::class)->createConsignment($item);
        $this->assertSame('https://steadfast.test/tl/log-link', $courier['tracking_url']);
        Log::shouldHaveReceived('info')->with('Steadfast consignment response', \Mockery::on(fn (array $context) => $context['http_status'] === 200 && $context['response']['consignment']['tracking_code'] === 'TRACK-42'))->once();

        $item->update(['courier_provider' => 'steadfast', 'courier_consignment_id' => '42', 'courier_invoice' => 'LOG-ORDER-ITEM-'.$item->id, 'fulfillment_status' => 'shipped']);
        $this->withHeader('Authorization', 'Bearer webhook-token')->postJson('/api/webhooks/steadfast', ['notification_type' => 'delivery_status', 'consignment_id' => 42, 'invoice' => 'LOG-ORDER-ITEM-'.$item->id, 'status' => 'delivered'])->assertOk();
        Log::shouldHaveReceived('info')->with('Steadfast webhook received', \Mockery::on(fn (array $context) => $context['payload']['consignment_id'] === 42))->once();
        Log::shouldHaveReceived('info')->with('Steadfast webhook processed', \Mockery::on(fn (array $context) => $context['order_item_id'] === $item->id))->once();
    }
}
