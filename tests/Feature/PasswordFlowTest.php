<?php

namespace Tests\Feature;

use App\Mail\PasswordResetCodeMail;
use App\Models\Admin;
use App\Models\Customer;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_reset_and_update_password_with_confirmation(): void
    {
        Mail::fake();
        $customer = Customer::create(['name' => 'Customer', 'email' => 'customer-password@example.test', 'password' => 'password123', 'email_verified_at' => now()]);
        $this->postJson('/api/customers/forgot-password', ['email' => $customer->email])->assertOk();
        $code = Mail::sent(PasswordResetCodeMail::class)->first()->code;
        $this->postJson('/api/customers/reset-password', ['email' => $customer->email, 'code' => $code, 'password' => 'resetpassword123', 'password_confirmation' => 'resetpassword123'])->assertOk();
        $this->assertTrue(Hash::check('resetpassword123', $customer->fresh()->password));
        $token = $customer->fresh()->createToken('test', ['customer:basic'])->plainTextToken;
        $this->withToken($token)->postJson('/api/customers/me/password', ['current_password' => 'resetpassword123', 'new_password' => 'updatedpassword123', 'new_password_confirmation' => 'updatedpassword123'])->assertOk();
        $this->assertTrue(Hash::check('updatedpassword123', $customer->fresh()->password));
    }

    public function test_admin_can_request_code_and_reset_password(): void
    {
        Mail::fake();
        $admin = Admin::create(['name' => 'Admin', 'email' => 'admin-password@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $this->postJson('/api/admin/forgot-password', ['email' => $admin->email])->assertOk();
        $code = Mail::sent(PasswordResetCodeMail::class)->first()->code;
        $this->postJson('/api/admin/reset-password', ['email' => $admin->email, 'code' => $code, 'password' => 'resetpassword123', 'password_confirmation' => 'resetpassword123'])->assertOk();
        $this->assertTrue(Hash::check('resetpassword123', $admin->fresh()->password));
    }

    public function test_seller_can_request_code_and_reset_password(): void
    {
        Mail::fake();
        $seller = Seller::create(['seller_name' => 'Seller', 'email' => 'seller-password@example.test', 'store_name' => 'Store', 'store_slug' => 'password-store', 'kyc_type' => 'nid', 'kyc_number' => '1', 'kyc_document_url' => 'https://example.test/kyc', 'product_category' => 'General', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $this->postJson('/api/sellers/forgot-password', ['email' => $seller->email])->assertOk();
        $code = Mail::sent(PasswordResetCodeMail::class)->first()->code;
        $this->postJson('/api/sellers/reset-password', ['email' => $seller->email, 'code' => $code, 'password' => 'resetpassword123', 'password_confirmation' => 'resetpassword123'])->assertOk();
        $this->assertTrue(Hash::check('resetpassword123', $seller->fresh()->password));
    }
}
