<?php

namespace Tests\Feature;

use App\Models\FraudGuardSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContactSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_requires_turnstile_when_configured_and_enforces_an_escalating_server_cooldown(): void
    {
        config(['services.turnstile.secret_key' => 'turnstile-secret']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);
        FraudGuardSetting::create([
            'enabled' => false,
            'ip_block' => false,
            'device_block' => false,
            'phone_blacklist' => false,
            'fake_number_detection' => false,
            'repeat_order_block' => false,
            'repeat_order_minutes' => 2,
        ]);

        $payload = ['name' => 'Guest', 'email' => 'contact-cooldown@example.test', 'subject' => 'Help', 'message' => 'Please help us.'];
        $this->postJson('/api/contact', $payload)->assertUnprocessable()->assertJsonValidationErrors('turnstile_token');
        $this->postJson('/api/contact', $payload + ['turnstile_token' => 'first-token'])->assertCreated();
        $blocked = $this->postJson('/api/contact', $payload + ['turnstile_token' => 'second-token'])->assertStatus(429);
        $this->assertGreaterThanOrEqual(119, (int) $blocked->headers->get('Retry-After'));

        $this->travel(2)->minutes();
        $this->postJson('/api/contact', $payload + ['turnstile_token' => 'third-token'])->assertCreated();
        $blockedAgain = $this->postJson('/api/contact', $payload + ['turnstile_token' => 'fourth-token'])->assertStatus(429);
        $this->assertGreaterThanOrEqual(239, (int) $blockedAgain->headers->get('Retry-After'));
    }
}
