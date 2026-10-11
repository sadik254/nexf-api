<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRosterTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_roster_combines_real_admin_seller_and_reseller_accounts_and_filters_roles(): void
    {
        $admin = Admin::create(['name' => 'Roster admin', 'email' => 'roster-admin@example.test', 'password' => 'password123', 'role' => 'super_admin']);
        $token = $admin->createToken('test', ['admin:manage-admins'])->plainTextToken;
        $this->withToken($token);
        $sellerId = \DB::table('sellers')->insertGetId([
            'seller_name' => 'Sam Seller', 'email' => 'seller@example.test', 'store_name' => 'Sam Store', 'store_slug' => 'sam-store',
            'kyc_type' => 'nid', 'kyc_number' => '12345', 'kyc_document_url' => 'https://example.test/kyc', 'product_category' => 'Clothing',
            'status' => 'approved', 'is_active' => true, 'password' => bcrypt('password123'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $resellerId = \DB::table('resellers')->insertGetId([
            'name' => 'Rae Reseller', 'email' => 'reseller@example.test', 'commission_rate' => 10, 'monthly_target' => 20,
            'is_active' => true, 'created_at' => now()->subDay(), 'updated_at' => now(),
        ]);
        Customer::create(['name' => 'Cory Customer', 'email' => 'customer@example.test', 'password' => 'password123']);

        $this->getJson('/api/admin/user-roster?per_page=10')->assertOk()->assertJsonPath('total', 4)->assertJsonPath('counts.all', 4)
            ->assertJsonFragment(['actor_type' => 'admin', 'role' => 'superadmin', 'status' => 'active'])
            ->assertJsonFragment(['actor_type' => 'seller', 'actor_id' => $sellerId, 'linked_to' => 'Sam Store'])
            ->assertJsonFragment(['actor_type' => 'reseller', 'actor_id' => $resellerId])
            ->assertJsonFragment(['actor_type' => 'customer', 'role' => 'customer']);
        $this->getJson('/api/admin/user-roster?role=seller')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.role', 'seller');
        $this->getJson('/api/admin/user-roster?search=Rae')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.actor_type', 'reseller');
    }

    public function test_user_roster_requires_manage_admins_ability(): void
    {
        $admin = Admin::create(['name' => 'Limited admin', 'email' => 'limited-admin@example.test', 'password' => 'password123', 'role' => 'admin']);
        $this->withToken($admin->createToken('test', ['admin:basic'])->plainTextToken)
            ->getJson('/api/admin/user-roster')->assertForbidden();
    }
}
