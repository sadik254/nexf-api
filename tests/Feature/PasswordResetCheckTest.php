<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PasswordResetCode;
use App\Services\PasswordResetCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordResetCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_code_check_does_not_consume_a_valid_code(): void
    {
        $customer = Customer::create(['name' => 'Shopper', 'email' => 'shop@example.test', 'password' => 'password123']);
        PasswordResetCode::create(['account_type' => 'customer', 'account_id' => $customer->id, 'email' => $customer->email, 'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10)]);

        $this->postJson('/api/customers/check-reset-code', ['email' => $customer->email, 'code' => '000000'])
            ->assertUnprocessable()->assertJsonPath('errors.code.0', 'Invalid or expired code.')->assertJsonMissingPath('code');
        $this->postJson('/api/customers/check-reset-code', ['email' => $customer->email, 'code' => '123456'])->assertOk();
        $this->postJson('/api/customers/reset-password', ['email' => $customer->email, 'code' => '123456', 'password' => 'abcd', 'password_confirmation' => 'abcd'])->assertOk();
        $this->postJson('/api/customers/check-reset-code', ['email' => $customer->email, 'code' => '123456'])
            ->assertUnprocessable()->assertJsonPath('code', 'code_used');
    }

    public function test_matched_codes_explain_expiry_replacement_and_completed_invites_without_leaking_wrong_codes(): void
    {
        $customer = Customer::create(['name' => 'Shopper', 'email' => 'lifecycle@example.test', 'password' => 'password123']);
        $service = app(PasswordResetCodeService::class);

        PasswordResetCode::create(['account_type' => 'customer', 'account_id' => $customer->id, 'email' => $customer->email, 'code_hash' => Hash::make('111111'), 'expires_at' => now()->subMinute()]);
        $this->postJson('/api/customers/check-reset-code', ['email' => $customer->email, 'code' => '111111'])
            ->assertUnprocessable()->assertJsonPath('code', 'code_expired');

        $oldInvite = $service->issue($customer, 'customer');
        $service->issue($customer, 'customer');
        $this->postJson('/api/customers/check-reset-code', ['email' => $customer->email, 'code' => $oldInvite])
            ->assertUnprocessable()->assertJsonPath('code', 'code_used');

        $invite = $service->issue($customer, 'customer');
        $this->postJson('/api/customers/reset-password', ['email' => $customer->email, 'code' => $invite, 'password' => 'abcd', 'password_confirmation' => 'abcd'])->assertOk();
        $this->postJson('/api/customers/check-reset-code', ['email' => $customer->email, 'code' => $invite])
            ->assertUnprocessable()->assertJsonPath('code', 'password_already_set');
    }

    public function test_suspended_account_reason_requires_a_matching_code(): void
    {
        $customer = Customer::create(['name' => 'Suspended', 'email' => 'suspended-reset@example.test', 'password' => 'password123', 'crm_status' => 'suspended']);
        $code = app(PasswordResetCodeService::class)->issue($customer, 'customer');

        $this->postJson('/api/customers/check-reset-code', ['email' => $customer->email, 'code' => '000000'])
            ->assertUnprocessable()->assertJsonMissingPath('code');
        $this->postJson('/api/customers/check-reset-code', ['email' => $customer->email, 'code' => $code])
            ->assertForbidden()->assertJsonPath('code', 'account_suspended');
    }
}
