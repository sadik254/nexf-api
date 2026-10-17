<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Order;
use App\Models\PaymentMethod;
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

    public function test_bank_transfer_requires_a_reference_and_non_manual_orders_cannot_submit(): void
    {
        $bank = PaymentMethod::where('code', 'bank')->firstOrFail();
        $bankOrder = $this->order($bank, 'bank');
        $this->postJson("/api/orders/{$bankOrder->id}/payment", ['phone' => '01712345678'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reference');

        $cod = PaymentMethod::create(['name' => 'Cash on Delivery', 'code' => 'cod', 'is_active' => true]);
        $codOrder = $this->order($cod, 'cod');
        $this->postJson("/api/orders/{$codOrder->id}/payment", ['phone' => '01712345678', 'sender' => '01712345678'])
            ->assertStatus(422);
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
