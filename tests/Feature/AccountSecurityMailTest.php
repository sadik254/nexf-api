<?php

namespace Tests\Feature;

use App\Mail\AdminPasswordResetMail;
use App\Mail\EmailChangedMail;
use App\Mail\PasswordChangedMail;
use App\Models\Admin;
use App\Models\Customer;
use App\Services\AccountSecurityMailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccountSecurityMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_emails_are_sent_for_password_and_email_changes(): void
    {
        Mail::fake();
        $customer = Customer::create(['name' => 'Buyer', 'email' => 'old@example.test', 'password' => 'password123']);
        app(AccountSecurityMailService::class)->passwordChanged($customer, 'settings', '127.0.0.1');
        app(AccountSecurityMailService::class)->emailChanged($customer, 'old@example.test', 'new@example.test');
        Mail::assertSent(PasswordChangedMail::class, fn ($mail) => $mail->hasTo('old@example.test'));
        Mail::assertSent(EmailChangedMail::class, 2);
    }

    public function test_super_admin_can_send_the_admin_reset_link(): void
    {
        Mail::fake();
        $super = Admin::create(['name' => 'Super', 'email' => 'super@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $admin = Admin::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $token = $super->createToken('test', ['admin'])->plainTextToken;
        $this->withToken($token)->postJson("/api/admin/admins/{$admin->id}/reset-password")->assertOk();
        Mail::assertSent(AdminPasswordResetMail::class, fn ($mail) => $mail->hasTo($admin->email));
    }
}
