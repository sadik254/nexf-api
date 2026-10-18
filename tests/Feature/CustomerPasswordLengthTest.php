<?php

namespace Tests\Feature;

use App\Mail\PasswordResetCodeMail;
use App\Models\Customer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPasswordLengthTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_registration_accepts_four_character_passwords(): void
    {
        $this->postJson('/api/customers', [
            'name' => 'Short Password', 'email' => 'short@example.test',
            'phone' => '01700000001', 'password' => 'abcd',
        ])->assertStatus(202);
        $this->postJson('/api/customers', [
            'name' => 'Too Short', 'email' => 'tiny@example.test', 'password' => 'abc',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_customer_reset_and_profile_password_accept_four_character_passwords(): void
    {
        Mail::fake();
        $customer = Customer::create(['name' => 'Shopper', 'email' => 'shopper@example.test', 'password' => 'password123']);
        $this->postJson('/api/customers/forgot-password', ['email' => $customer->email])->assertOk();
        $resetCode = Mail::sent(PasswordResetCodeMail::class)->first()->code;
        $this->postJson('/api/customers/reset-password', [
            'email' => $customer->email, 'code' => $resetCode, 'password' => 'abcd', 'password_confirmation' => 'abcd',
        ])->assertOk();
        $this->withToken($customer->fresh()->createToken('test', ['customer:basic'])->plainTextToken)
            ->postJson('/api/customers/me/password', [
                'current_password' => 'abcd', 'new_password' => 'wxyz', 'new_password_confirmation' => 'wxyz',
            ])->assertOk();
    }
}
