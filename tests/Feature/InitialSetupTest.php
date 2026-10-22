<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InitialSetupTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'admin_name' => 'NEXF Owner',
            'admin_email' => 'owner@example.test',
            'admin_phone' => '01712345678',
            'admin_password' => 'password123',
            'admin_password_confirmation' => 'password123',
            'store_name' => 'NEXF Lifestyle',
            'sku_prefix' => 'NEXF',
            'store_email' => 'support@example.test',
            'store_phone' => '01812345678',
            'address_line' => '12 Test Road',
            'address_district' => 'Dhaka',
            'address_area' => 'Gulshan',
        ], $overrides);
    }

    public function test_setup_saves_the_same_store_contact_fields_as_seller_signup(): void
    {
        $this->post('/setup', $this->payload())->assertRedirect('/');

        $store = Store::primary();
        $this->assertNotNull($store);
        $this->assertSame('NEXF', $store->sku_prefix);
        $this->assertSame('support@example.test', $store->support_email);
        $this->assertSame('01812345678', $store->support_phone);
        $this->assertSame('12 Test Road, Gulshan, Dhaka', $store->store_address);
        $admin = Admin::where('email', 'owner@example.test')->firstOrFail();
        $this->assertSame('super_admin', $admin->role);
        $this->assertTrue(Hash::check('password123', $admin->password));
    }

    public function test_setup_shows_plain_validation_errors(): void
    {
        $this->from('/setup')->post('/setup', $this->payload([
            'sku_prefix' => 'ABC',
            'address_area' => 'Not a Dhaka thana',
            'admin_password_confirmation' => 'different',
        ]))->assertRedirect('/setup')->assertSessionHasErrors([
            'sku_prefix' => 'Use exactly 4 letters or digits.',
            'address_area' => 'Select your thana from the list.',
            'admin_password' => "Passwords don't match.",
        ]);
    }

    public function test_setup_page_renders_the_signup_fields(): void
    {
        $this->get('/setup')->assertOk()
            ->assertSee('Full name')
            ->assertSee('SKU code')
            ->assertSee('Area / Thana')
            ->assertSee('Store logo');
    }
}
