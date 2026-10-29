<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletRewardSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_read_and_replace_reward_settings(): void
    {
        $super = Admin::create(['name' => 'Super', 'email' => 'rewards-super@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $token = $super->createToken('test', ['admin'])->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/wallet-rewards')
            ->assertOk()->assertExactJson([
                'cashback_enabled' => true, 'cashback_percent' => 2,
                'review_enabled' => true, 'review_amount' => 20,
                'referral_enabled' => true, 'referral_amount' => 50,
            ]);

        $payload = ['cashback_enabled' => false, 'cashback_percent' => 7.5, 'review_enabled' => true, 'review_amount' => 35, 'referral_enabled' => false, 'referral_amount' => 75];
        $this->withToken($token)->postJson('/api/admin/wallet-rewards', $payload)
            ->assertOk()->assertExactJson($payload);
        $this->assertDatabaseHas('site_settings', ['key' => 'wallet_rewards']);
    }

    public function test_admin_cannot_manage_rewards_and_values_are_validated(): void
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => 'rewards-admin@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $token = $admin->createToken('test', ['admin'])->plainTextToken;
        $this->withToken($token)->getJson('/api/admin/wallet-rewards')->assertForbidden()->assertJsonPath('permission', 'rewards.manage');

        $super = Admin::create(['name' => 'Super', 'email' => 'rewards-validate@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $superToken = $super->createToken('test', ['admin'])->plainTextToken;
        $this->withToken($superToken)->postJson('/api/admin/wallet-rewards', ['cashback_enabled' => true, 'cashback_percent' => 101])
            ->assertUnprocessable()->assertJsonValidationErrors(['cashback_percent', 'review_enabled', 'review_amount', 'referral_enabled', 'referral_amount']);
    }
}
