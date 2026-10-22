<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerVerificationCode;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestOrderClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_count_is_phone_scoped_and_only_counts_unclaimed_guest_orders(): void
    {
        $waiting = $this->guestOrder('01712345678', 'WAITING');
        $this->guestOrder('01700000000', 'OTHER');
        $owned = $this->guestOrder('01712345678', 'OWNED');
        $owned->update(['customer_id' => $this->customer('owned@example.test', '01719999999')->id]);

        $this->getJson('/api/guest-orders/pending-count?phone=%2B8801712345678')
            ->assertOk()
            ->assertExactJson(['count' => 1]);
        $this->assertNull($waiting->fresh()->customer_id);
        $this->getJson('/api/guest-orders/pending-count?phone=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_email_verification_claims_matching_guest_orders_and_makes_them_visible_to_the_customer(): void
    {
        $customer = $this->customer('claim@example.test', '+8801712345678', false);
        $matching = $this->guestOrder('01712345678', 'CLAIM-ME');
        $different = $this->guestOrder('01700000000', 'LEAVE-ME');
        $alreadyOwned = $this->guestOrder('01712345678', 'ALREADY-OWNED');
        $otherCustomer = $this->customer('other@example.test', '01718888888');
        $alreadyOwned->update(['customer_id' => $otherCustomer->id]);
        CustomerVerificationCode::create([
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'code' => '123456',
            'type' => 'email_verification',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/customers/verify-email', ['email' => $customer->email, 'code' => '123456'])
            ->assertOk()
            ->assertJsonPath('claimed_orders', 1);

        $this->assertSame($customer->id, $matching->fresh()->customer_id);
        $this->assertFalse($matching->fresh()->is_guest);
        $this->assertNull($different->fresh()->customer_id);
        $this->assertSame($otherCustomer->id, $alreadyOwned->fresh()->customer_id);

        $token = $customer->fresh()->createToken('test', ['customer:basic'])->plainTextToken;
        $this->withToken($token)->getJson('/api/customers/orders')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.order_number', 'CLAIM-ME');
    }

    private function guestOrder(string $phone, string $number): Order
    {
        return Order::create([
            'order_number' => $number,
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'subtotal' => 100,
            'total' => 100,
            'shipping_charge' => 0,
            'shipping_name' => 'Guest Buyer',
            'shipping_phone' => $phone,
            'shipping_address' => 'Dhaka',
            'is_guest' => true,
        ]);
    }

    private function customer(string $email, string $phone, bool $verified = true): Customer
    {
        return Customer::create([
            'name' => 'Test Customer',
            'email' => $email,
            'phone' => $phone,
            'password' => 'password123',
            'email_verified_at' => $verified ? now() : null,
        ]);
    }
}
