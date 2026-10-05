<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageOfferBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_admin_can_update_live_offer_blocks(): void
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => 'admin-offer@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $super = Admin::create(['name' => 'Super', 'email' => 'super-offer@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $this->getJson('/api/homepage/offer-blocks')->assertOk()->assertJsonCount(7);
        $this->withToken($admin->createToken('test', ['admin:basic'])->plainTextToken)
            ->postJson('/api/admin/homepage/offer-blocks/1', ['title' => 'Updated'])->assertForbidden();
        $token = $super->createToken('test', ['admin:basic'])->plainTextToken;
        $this->withToken($token)->postJson('/api/admin/homepage/offer-blocks/1', [
            'title' => 'Autumn collection', 'href' => '/shop?category=women', 'hidden' => false,
        ])->assertOk()->assertJsonPath('title', 'Autumn collection');
        $this->getJson('/api/homepage/offer-blocks')->assertOk()->assertJsonPath('0.title', 'Autumn collection');
        $this->withToken($token)->postJson('/api/admin/homepage/offer-blocks/1', ['href' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/admin/homepage/offer-blocks/1', ['hidden' => true])->assertOk();
        $this->getJson('/api/homepage/offer-blocks')->assertOk()->assertJsonCount(6);
    }
}
