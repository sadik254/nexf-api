<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageTrustBadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_replace_badges_and_public_sees_only_visible_badges(): void
    {
        $super = Admin::create(['name' => 'Super', 'email' => 'super-badge@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $admin = Admin::create(['name' => 'Admin', 'email' => 'admin-badge@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $payload = ['badges' => [
            ['icon' => 'Truck', 'label' => 'Fast delivery', 'tone' => 'blue', 'hidden' => false],
            ['icon' => 'Crown', 'label' => 'Draft', 'tone' => 'pink', 'hidden' => true],
        ]];
        $this->withToken($admin->createToken('test', ['admin:basic'])->plainTextToken)->postJson('/api/admin/homepage/trust-badges', $payload)->assertForbidden();
        $token = $super->createToken('test', ['admin:basic'])->plainTextToken;
        $this->withToken($token)->postJson('/api/admin/homepage/trust-badges', $payload)->assertOk()->assertJsonCount(2);
        $this->getJson('/api/homepage/trust-badges')->assertOk()->assertJsonCount(1)->assertJsonPath('0.label', 'Fast delivery');
        $this->withToken($token)->postJson('/api/admin/homepage/trust-badges', ['badges' => [
            ['icon' => 'ShieldCheck', 'label' => 'Secure payments', 'tone' => 'emerald', 'hidden' => false],
        ]])->assertOk()->assertJsonCount(1);
        $this->getJson('/api/homepage/trust-badges')->assertOk()->assertJsonPath('0.label', 'Secure payments');
    }
}
