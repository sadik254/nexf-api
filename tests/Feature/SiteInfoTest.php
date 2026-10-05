<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteInfoTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_admin_can_change_public_footer_content(): void
    {
        $default = $this->getJson('/api/site-info')->assertOk()->json();
        $this->assertSame('NEXF Lifestyle Ltd.', $default['name']);

        $admin = Admin::create(['name' => 'Admin', 'email' => 'admin-footer@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $super = Admin::create(['name' => 'Super', 'email' => 'super-footer@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $adminToken = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $superToken = $super->createToken('test', ['admin:basic'])->plainTextToken;

        $changed = [...$default, 'blurb' => 'Updated marketplace description'];
        $this->withToken($adminToken)->postJson('/api/admin/site-info', $changed)->assertForbidden();
        $this->withToken($superToken)->postJson('/api/admin/site-info', [...$changed, 'locatorHref' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->withToken($superToken)->postJson('/api/admin/site-info', $changed)->assertOk()->assertJsonPath('blurb', 'Updated marketplace description');
        $this->getJson('/api/site-info')->assertOk()->assertJsonPath('blurb', 'Updated marketplace description');
        $changed['socials'][0]['enabled'] = false;
        $changed['socials'][0]['href'] = '';
        $this->withToken($superToken)->postJson('/api/admin/site-info', $changed)->assertOk()->assertJsonPath('socials.0.href', '');
    }
}
