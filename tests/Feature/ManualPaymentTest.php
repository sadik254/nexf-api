<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\SiteSetting;
use App\Services\ManualPaymentExpiryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_save_and_public_can_read_manual_payment_accounts(): void
    {
        $admin = Admin::create([
            'name' => 'Payments Admin',
            'email' => 'payments-admin@example.test',
            'password' => 'password123',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $accounts = [
            ['id' => 'bkash', 'enabled' => true, 'number' => '01712345678', 'accountType' => 'Merchant', 'note' => 'Use Send Money'],
            ['id' => 'upay', 'enabled' => true, 'number' => '01912345678', 'accountType' => 'Merchant'],
            ['id' => 'bank', 'enabled' => false, 'number' => '', 'bankName' => 'Example Bank'],
        ];

        $token = $admin->createToken('test', ['admin:payment-methods'])->plainTextToken;
        $this->withToken($token)->postJson('/api/admin/payment-accounts', ['accounts' => $accounts])
            ->assertOk()
            ->assertExactJson($accounts);

        $this->getJson('/api/payment-accounts')->assertOk()->assertExactJson($accounts);

        $this->withToken($token)->postJson('/api/admin/payment-accounts', [
            'accounts' => [['id' => 'nagad', 'enabled' => true, 'number' => '']],
        ])->assertUnprocessable()->assertJsonValidationErrors('accounts.0.number');
    }

    public function test_shopper_can_submit_and_read_manual_payment_using_delivery_phone_only(): void
    {
        $method = PaymentMethod::where('code', 'bkash')->firstOrFail();
        $order = Order::create([
            'order_number' => 'MANUAL-PAY-1',
            'payment_method_id' => $method->id,
            'payment_method_code' => 'bkash',
            'payment_method_name' => 'bKash',
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'subtotal' => 100,
            'total' => 100,
            'shipping_charge' => 0,
            'shipping_name' => 'Buyer',
            'shipping_phone' => '+8801712345678',
            'shipping_address' => 'Dhaka',
        ]);

        $this->postJson("/api/orders/{$order->id}/payment", [
            'phone' => '01712345678',
            'sender' => '01712345678',
            'reference' => 'ab12-cd34',
        ])->assertOk()
            ->assertJsonPath('id', $order->id)
            ->assertJsonPath('payment_reference', 'AB12-CD34')
            ->assertJsonPath('payment_sender', '01712345678')
            ->assertJsonPath('payment_status', 'unpaid');

        $this->getJson("/api/orders/{$order->id}/payment?phone=01712345678")
            ->assertOk()
            ->assertJsonPath('payment_reference', 'AB12-CD34');
        $this->getJson("/api/orders/{$order->id}/payment?phone=01700000000")->assertNotFound();
    }

    public function test_bank_transfer_requires_a_bank_name_but_not_a_reference(): void
    {
        $bank = PaymentMethod::where('code', 'bank')->firstOrFail();
        $bankOrder = $this->order($bank, 'bank');

        $this->postJson("/api/orders/{$bankOrder->id}/payment", ['phone' => '01712345678'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sender');

        $this->postJson("/api/orders/{$bankOrder->id}/payment", [
            'phone' => '01712345678',
            'sender' => 'Example Bank PLC',
            'amount' => 35,
        ])->assertOk()
            ->assertJsonPath('payment_sender', 'Example Bank PLC')
            ->assertJsonPath('payment_reference', null);

        $this->assertDatabaseHas('orders', ['id' => $bankOrder->id, 'payment_amount' => 35]);
    }

    public function test_pending_cod_order_can_switch_to_an_enabled_manual_method(): void
    {
        $cod = PaymentMethod::create(['name' => 'Cash on Delivery', 'code' => 'cod', 'is_active' => true]);
        $order = $this->order($cod, 'cod');
        SiteSetting::create(['key' => 'payment_accounts', 'payload' => [
            ['id' => 'nagad', 'enabled' => true, 'number' => '01812345678'],
        ]]);

        $this->postJson("/api/orders/{$order->id}/payment", [
            'phone' => '01712345678',
            'method' => 'nagad',
            'sender' => '01712345678',
            'reference' => 'ab12-cd34',
            'amount' => 40,
        ])->assertOk()
            ->assertJsonPath('payment_method_code', 'nagad')
            ->assertJsonPath('payment_amount', '40.00');
    }

    public function test_admin_can_record_and_delete_a_manual_payment(): void
    {
        $method = PaymentMethod::where('code', 'bkash')->firstOrFail();
        $order = $this->order($method, 'bkash');
        $admin = Admin::create([
            'name' => 'Orders Admin', 'email' => 'orders-admin@example.test',
            'password' => 'password123', 'role' => 'admin', 'is_active' => true,
        ]);
        $token = $admin->createToken('test', ['admin:orders'])->plainTextToken;

        $this->withToken($token)->postJson("/api/admin/orders/{$order->id}", [
            'paid_amount' => 25,
            'payment_sender' => 'Counter payment',
            'payment_reference' => 'Manual entry',
        ])->assertOk();
        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'paid_amount' => 25,
            'payment_sender' => 'Counter payment', 'payment_reference' => 'Manual entry',
        ]);
        $this->assertNotNull($order->fresh()->payment_received_at);

        $this->withToken($token)->postJson("/api/admin/orders/{$order->id}/payment/delete")
            ->assertOk()
            ->assertJsonPath('message', 'Payment deleted.');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'paid_amount' => 0]);
        $this->assertNull($order->fresh()->payment_received_at);
    }


    public function test_expired_unpaid_manual_order_is_cancelled_before_a_late_payment_can_be_submitted(): void
    {
        $method = PaymentMethod::where('code', 'bkash')->firstOrFail();
        $order = $this->order($method, 'bkash');
        $order->update(['placed_at' => now()->subMinutes(31)]);

        $this->postJson("/api/orders/{$order->id}/payment", [
            'phone' => '01712345678', 'sender' => '01712345678', 'reference' => 'ab12-cd34',
        ])->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_expiry_service_leaves_a_manual_order_with_submitted_details_open(): void
    {
        $method = PaymentMethod::where('code', 'bkash')->firstOrFail();
        $order = $this->order($method, 'bkash');
        $order->update(['placed_at' => now()->subMinutes(31), 'payment_sender' => '01712345678']);

        $this->assertFalse(app(ManualPaymentExpiryService::class)->cancelIfExpired($order));
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_confirmed_manual_payment_cannot_be_overwritten_by_shopper(): void
    {
        $method = PaymentMethod::where('code', 'bkash')->firstOrFail();
        $order = $this->order($method, 'bkash');
        $order->update(['payment_sender' => '01712345678', 'payment_reference' => 'ORIGINAL', 'paid_amount' => 100]);

        $this->postJson("/api/orders/{$order->id}/payment", [
            'phone' => '01712345678', 'sender' => '01812345678', 'reference' => 'REPLACED',
        ])->assertUnprocessable()->assertJsonPath('message', 'This payment is already confirmed.');

        $this->assertSame('ORIGINAL', $order->fresh()->payment_reference);
    }

    public function test_admin_can_reject_submitted_manual_payment(): void
    {
        $method = PaymentMethod::where('code', 'bkash')->firstOrFail();
        $order = $this->order($method, 'bkash');
        $order->update(['payment_sender' => '01712345678', 'payment_reference' => 'SUBMITTED']);
        $admin = Admin::create(['name' => 'Payment Admin', 'email' => 'payment-reject@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);

        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
            ->postJson("/api/admin/orders/{$order->id}/payment/reject")
            ->assertOk()->assertJsonPath('order.status', 'cancelled');
        $this->assertNotNull($order->fresh()->payment_rejected_at);
        $this->assertSame('admin', $order->fresh()->cancelled_by);
    }

    private function order(PaymentMethod $method, string $code): Order
    {
        return Order::create([
            'order_number' => "MANUAL-{$code}",
            'payment_method_id' => $method->id,
            'payment_method_code' => $code,
            'payment_method_name' => $method->name,
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'subtotal' => 100,
            'total' => 100,
            'shipping_charge' => 0,
            'shipping_name' => 'Buyer',
            'shipping_phone' => '01712345678',
            'shipping_address' => 'Dhaka',
        ]);
    }
}
