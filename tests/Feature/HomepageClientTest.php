<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_clients_are_replaced_in_order_and_home_layout_keeps_client_settings(): void
    {
        config()->set('filesystems.disks.r2.url', 'https://media.example.test');
        $super = Admin::create(['name' => 'Super', 'email' => 'clients@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $token = $super->createToken('test', ['admin'])->plainTextToken;
        $clients = [
            ['name' => 'Visible client', 'logo' => 'https://media.example.test/media/client-one.svg', 'url' => 'https://client-one.test', 'industry' => 'Retail', 'hidden' => false],
            ['name' => 'Hidden client', 'logo' => 'https://media.example.test/media/client-two.png', 'url' => null, 'industry' => null, 'hidden' => true],
        ];

        $saved = $this->withToken($token)->postJson('/api/admin/homepage/clients', ['clients' => $clients])
            ->assertOk()->assertJsonCount(2)->assertJsonPath('0.name', 'Visible client')->json();
        $this->getJson('/api/homepage/clients')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.original_logo', 'https://media.example.test/media/client-one.svg');

        $this->withToken($token)->postJson('/api/admin/homepage/clients', ['clients' => [[
            ...$clients[1], 'id' => $saved[1]['id'], 'hidden' => false,
        ]]])->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Hidden client');

        $layout = [[
            'id' => 'clients', 'type' => 'clients', 'enabled' => true, 'title' => 'Trusted by clients',
            'subtitle' => 'Across Bangladesh', 'clientsLayout' => 'marquee', 'limit' => 12,
        ]];
        $this->withToken($token)->postJson('/api/admin/homepage/layout', ['sections' => $layout])
            ->assertOk()->assertJsonPath('0.clientsLayout', 'marquee')->assertJsonPath('0.subtitle', 'Across Bangladesh');
    }
}
