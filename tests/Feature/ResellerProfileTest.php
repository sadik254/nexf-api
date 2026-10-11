<?php

namespace Tests\Feature;

use App\Models\Reseller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseller_can_update_own_profile_fields(): void
    {
        $reseller = Reseller::create([
            'name' => 'Original Name', 'email' => 'profile-reseller@example.test',
            'phone' => '01700000000', 'password' => 'password123', 'is_active' => true,
        ]);
        $token = $reseller->createToken('test', ['reseller:basic'])->plainTextToken;

        $this->withToken($token)->postJson('/api/resellers/me', [
            'name' => 'Updated Name', 'email' => 'updated-reseller@example.test', 'phone' => '01800000000',
        ])->assertOk()->assertJsonPath('name', 'Updated Name')
            ->assertJsonPath('email', 'updated-reseller@example.test')
            ->assertJsonPath('phone', '01800000000')->assertJsonPath('image', null);

        $this->assertDatabaseHas('resellers', ['id' => $reseller->id, 'name' => 'Updated Name']);
    }
}
