<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\HomepageBanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageBannerVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_last_visible_hero_cannot_be_hidden_or_deleted(): void
    {
        $admin = Admin::create(['name' => 'Super', 'email' => 'banners@example.test', 'password' => 'password123', 'role' => 'super_admin']);
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $hero = HomepageBanner::create(['placement' => 'hero', 'image' => 'https://example.test/hero.jpg', 'href' => '/', 'is_active' => true]);

        $this->withToken($token)->postJson("/api/admin/homepage/banners/{$hero->id}", ['is_active' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->withToken($token)->postJson("/api/admin/homepage/banners/{$hero->id}/delete")
            ->assertUnprocessable()->assertJsonValidationErrors('is_active');

        HomepageBanner::create(['placement' => 'hero', 'image' => 'https://example.test/hero-2.jpg', 'href' => '/', 'is_active' => true]);
        $this->withToken($token)->postJson("/api/admin/homepage/banners/{$hero->id}", ['is_active' => false])->assertOk();
        $this->withToken($token)->postJson("/api/admin/homepage/banners/{$hero->id}/delete")->assertOk();
    }
}
