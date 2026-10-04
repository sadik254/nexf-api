<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_notice_bar_is_managed_by_super_admin_and_only_shows_enabled_messages(): void
    {
        $super = Admin::create(['name' => 'Super', 'email' => 'super-notice@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $admin = Admin::create(['name' => 'Admin', 'email' => 'admin-notice@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $adminToken = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $superToken = $super->createToken('test', ['admin:basic'])->plainTextToken;

        $this->withToken($adminToken)->postJson('/api/admin/homepage/notices', ['text' => 'Not allowed'])->assertForbidden();
        $firstId = $this->withToken($superToken)->postJson('/api/admin/homepage/notices', ['text' => 'Free delivery', 'enabled' => true])->assertCreated()->json('id');
        $secondId = $this->withToken($superToken)->postJson('/api/admin/homepage/notices', ['text' => 'Private draft', 'enabled' => false])->assertCreated()->json('id');

        $this->getJson('/api/homepage/notices')->assertOk()->assertJsonCount(1)->assertJsonPath('0.text', 'Free delivery');
        $this->withToken($superToken)->getJson('/api/admin/homepage/notices')->assertOk()->assertJsonCount(2);
        $this->withToken($superToken)->postJson("/api/admin/homepage/notices/{$secondId}", ['enabled' => true, 'sort_order' => 0])->assertOk();
        $this->getJson('/api/homepage/notices')->assertOk()->assertJsonPath('0.text', 'Private draft');
        $this->withToken($superToken)->postJson("/api/admin/homepage/notices/{$firstId}/delete")->assertOk();
        $this->getJson('/api/homepage/notices')->assertOk()->assertJsonCount(1);
    }
}
