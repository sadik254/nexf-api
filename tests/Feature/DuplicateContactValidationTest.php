<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class DuplicateContactValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_duplicate_contacts_are_attached_to_their_fields(): void
    {
        $existing = Customer::create(['name' => 'Existing', 'email' => 'existing-customer@example.test', 'phone' => '01700000001', 'password' => 'password123']);
        $current = Customer::create(['name' => 'Current', 'email' => 'current-customer@example.test', 'phone' => '01700000002', 'password' => 'password123']);

        $this->postJson('/api/customers', ['name' => 'New', 'email' => $existing->email, 'phone' => '01700000003', 'password' => 'password123'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/customers', ['name' => 'New', 'email' => 'new-customer@example.test', 'phone' => $existing->phone, 'password' => 'password123'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');

        $token = $current->createToken('test', ['customer:basic'])->plainTextToken;
        $this->withToken($token)->postJson('/api/customers/me', ['email' => $existing->email])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->withToken($token)->postJson('/api/customers/me', ['phone' => $existing->phone])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_admin_duplicate_contacts_are_attached_to_their_fields(): void
    {
        $super = $this->admin('super@example.test', '01700000010', 'super_admin');
        $existing = $this->admin('existing-admin@example.test', '01700000011');
        $target = $this->admin('target-admin@example.test', '01700000012');
        $token = $super->createToken('test', ['admin'])->plainTextToken;

        $this->withToken($token)->postJson('/api/admin/create', ['name' => 'New', 'email' => $existing->email])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->withToken($token)->postJson('/api/admin/create', ['name' => 'New', 'email' => 'new-admin@example.test', 'phone' => $existing->phone])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->withToken($token)->postJson("/api/admin/admins/{$target->id}", ['email' => $existing->email])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->withToken($token)->postJson("/api/admin/admins/{$target->id}", ['phone' => $existing->phone])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->withToken($token)->postJson('/api/admin/me', ['email' => $existing->email])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->withToken($token)->postJson('/api/admin/me', ['phone' => $existing->phone])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_seller_onboarding_duplicate_email_is_a_field_error(): void
    {
        $this->seller('taken-seller@example.test');

        $this->post('/api/sellers/onboard', [
            'seller_name' => 'Applicant',
            'email' => 'taken-seller@example.test',
            'phone' => '01700000020',
            'store_name' => 'Applicant Store',
            'kyc_type' => 'nid',
            'kyc_number' => '12345678',
            'kyc_document' => UploadedFile::fake()->create('nid.png', 10, 'image/png'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    private function admin(string $email, string $phone, string $role = 'admin'): Admin
    {
        return Admin::create(['name' => 'Admin', 'email' => $email, 'phone' => $phone, 'password' => 'password123', 'role' => $role, 'is_active' => true]);
    }

    private function seller(string $email): Seller
    {
        return Seller::create([
            'seller_name' => 'Seller', 'email' => $email, 'store_name' => 'Taken Store', 'store_slug' => 'taken-store',
            'kyc_type' => 'nid', 'kyc_number' => '12345678', 'kyc_document_url' => 'https://example.test/nid.png',
            'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123',
        ]);
    }
}
