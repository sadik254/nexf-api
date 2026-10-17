<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerVerificationCode;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPendingRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function register(array $overrides = []): void
    {
        $this->postJson('/api/customers', $overrides + [
            'name' => 'New shopper', 'email' => 'new@example.test',
            'phone' => '01700000001', 'password' => 'password123',
        ])->assertStatus(202)->assertJsonMissingPath('customer');
    }

    private function code(): string
    {
        return CustomerVerificationCode::query()->where('email', 'new@example.test')->whereNull('used_at')->latest('id')->value('code');
    }

    public function test_customer_is_created_only_after_email_verification_and_claims_guest_orders(): void
    {
        Order::create([
            'order_number' => 'GUEST-REG-1', 'status' => 'pending', 'payment_status' => 'unpaid',
            'subtotal' => 100, 'total' => 100, 'shipping_charge' => 0, 'shipping_name' => 'Guest',
            'shipping_phone' => '01700000001', 'shipping_address' => 'Dhaka', 'is_guest' => true,
        ]);
        $this->register();

        $this->assertDatabaseMissing('customers', ['email' => 'new@example.test']);
        $this->assertDatabaseHas('pending_customer_registrations', ['email' => 'new@example.test']);
        $this->postJson('/api/customers/login', ['email' => 'new@example.test', 'password' => 'password123'])->assertUnauthorized();

        $this->postJson('/api/customers/verify-email', ['email' => 'new@example.test', 'code' => $this->code()])
            ->assertOk()->assertJsonPath('claimed_orders', 1);

        $customer = Customer::where('email', 'new@example.test')->firstOrFail();
        $this->assertNotNull($customer->email_verified_at);
        $this->assertDatabaseMissing('pending_customer_registrations', ['email' => 'new@example.test']);
        $this->assertDatabaseHas('orders', ['order_number' => 'GUEST-REG-1', 'customer_id' => $customer->id]);
    }

    public function test_repeat_registration_replaces_pending_details_and_resend_works(): void
    {
        $this->register();
        $this->register(['name' => 'Second attempt', 'password' => 'another123']);
        $this->postJson('/api/customers/resend-verification', ['email' => 'new@example.test'])->assertOk();
        $this->postJson('/api/customers/verify-email', ['email' => 'new@example.test', 'code' => $this->code()])->assertOk();

        $customer = Customer::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('Second attempt', $customer->name);
        $this->assertTrue(password_verify('another123', $customer->password));
    }
}
