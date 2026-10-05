<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_layout_is_persistent_ordered_and_super_admin_only(): void
    {
        $this->getJson('/api/homepage/layout')->assertOk()->assertJsonPath('0.type', 'hero');
        $admin = Admin::create(['name' => 'Admin', 'email' => 'admin-layout@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $super = Admin::create(['name' => 'Super', 'email' => 'super-layout@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $sections = [['id' => 'trust', 'type' => 'trust', 'enabled' => true], ['id' => 'hero', 'type' => 'hero', 'enabled' => false]];
        $this->withToken($admin->createToken('test', ['admin:basic'])->plainTextToken)
            ->postJson('/api/admin/homepage/layout', ['sections' => $sections])->assertForbidden();
        $token = $super->createToken('test', ['admin:basic'])->plainTextToken;
        $this->withToken($token)->postJson('/api/admin/homepage/layout', ['sections' => $sections])->assertOk()->assertJsonPath('0.type', 'trust');
        $this->getJson('/api/homepage/layout')->assertOk()->assertJsonCount(2)->assertJsonPath('1.enabled', false);
        $this->withToken($token)->postJson('/api/admin/homepage/layout', ['sections' => [
            ['id' => 'collection', 'type' => 'collection', 'enabled' => true],
        ]])->assertUnprocessable();
    }
}
