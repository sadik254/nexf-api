<?php

namespace Tests\Feature;

use App\Mail\StaffInvitedMail;
use App\Models\PasswordResetCode;
use App\Models\Seller;
use App\Models\SellerStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SellerStaffCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_invites_staff_for_seven_days_and_cannot_activate_them_before_password_setup(): void
    {
        Mail::fake();
        $seller = $this->seller();
        $token = $seller->createToken('test', ['seller'])->plainTextToken;

        $staff = $this->withToken($token)->postJson('/api/seller/staff', [
            'name' => 'Store worker', 'email' => 'worker@example.test', 'status' => 'active',
        ])->assertCreated()->assertJsonPath('status', 'invited')->json();

        Mail::assertSent(StaffInvitedMail::class);
        $code = PasswordResetCode::where('account_type', 'seller_staff')->where('account_id', $staff['id'])->firstOrFail();
        $this->assertTrue($code->expires_at->between(now()->addDays(6)->endOfDay(), now()->addDays(7)->addMinute()));

        $this->withToken($token)->postJson("/api/seller/staff/{$staff['id']}", ['status' => 'active'])
            ->assertOk()->assertJsonPath('status', 'invited');
    }

    public function test_staff_email_is_unique_against_seller_email_and_own_profile_cannot_change_it_directly(): void
    {
        Mail::fake();
        $seller = $this->seller();
        $ownerToken = $seller->createToken('test', ['seller'])->plainTextToken;
        $this->withToken($ownerToken)->postJson('/api/seller/staff', [
            'name' => 'Duplicate', 'email' => $seller->email,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $staff = SellerStaff::create(['seller_id' => $seller->id, 'name' => 'Worker', 'email' => 'worker@example.test', 'password' => 'password123', 'status' => 'active']);
        $staffToken = $staff->createToken('test', ['seller'])->plainTextToken;
        $this->withToken($staffToken)->postJson('/api/seller/staff/me', ['email' => 'changed@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame('worker@example.test', $staff->fresh()->email);
    }

    private function seller(): Seller
    {
        return Seller::create(['seller_name' => 'Owner', 'email' => 'owner@example.test', 'store_name' => 'Owner Store', 'store_slug' => 'owner-store', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
    }
}
