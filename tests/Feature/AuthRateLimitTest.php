<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\PasswordResetCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_429_after_five_attempts_for_the_same_ip_and_email(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/customers/login', ['email' => 'limit@example.test', 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/customers/login', ['email' => 'limit@example.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_a_reset_code_is_spent_after_five_wrong_attempts(): void
    {
        $customer = Customer::create(['name' => 'Limit', 'email' => 'reset-limit@example.test', 'password' => 'password123']);
        $code = app(PasswordResetCodeService::class)->issue($customer, 'customer');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->assertFalse(app(PasswordResetCodeService::class)->check($customer, 'customer', '000000'));
        }
        $this->assertFalse(app(PasswordResetCodeService::class)->check($customer, 'customer', $code));
    }
}
