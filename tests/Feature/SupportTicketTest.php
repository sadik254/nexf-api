<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_seller_and_admin_only_see_their_tickets_and_can_reply(): void
    {
        $customer = Customer::create(['name' => 'Buyer', 'email' => 'buyer-support@example.test', 'password' => 'password123']);
        $other = Customer::create(['name' => 'Other', 'email' => 'other-support@example.test', 'password' => 'password123']);
        $seller = Seller::create(['seller_name' => 'Seller', 'email' => 'seller-support@example.test', 'store_name' => 'Store', 'store_slug' => 'support-store', 'kyc_type' => 'nid', 'kyc_number' => '1', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $otherSeller = Seller::create(['seller_name' => 'Other seller', 'email' => 'other-seller-support@example.test', 'store_name' => 'Other store', 'store_slug' => 'other-support-store', 'kyc_type' => 'nid', 'kyc_number' => '2', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $admin = Admin::create(['name' => 'Admin', 'email' => 'admin-support@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $customerToken = $customer->createToken('test', ['customer:basic'])->plainTextToken;
        $ticketId = $this->withToken($customerToken)->postJson('/api/customers/support-tickets', [
            'seller_id' => $seller->id, 'category' => 'Delivery', 'subject' => 'Parcel delayed', 'body' => 'Where is my parcel?',
        ])->assertCreated()->assertJsonPath('messages.0.author_type', 'customer')->json('id');
        $this->withToken($other->createToken('test', ['customer:basic'])->plainTextToken)
            ->getJson("/api/customers/support-tickets/{$ticketId}")->assertNotFound();
        $this->withToken($otherSeller->createToken('test', ['seller:basic'])->plainTextToken)
            ->getJson("/api/seller/support-tickets/{$ticketId}")->assertNotFound();
        $sellerToken = $seller->createToken('test', ['seller:basic'])->plainTextToken;
        $this->withToken($sellerToken)->getJson('/api/seller/support-tickets')->assertOk()->assertJsonPath('total', 1);
        $this->withToken($sellerToken)->postJson("/api/seller/support-tickets/{$ticketId}/messages", ['body' => 'We are checking.'])
            ->assertOk()->assertJsonCount(2, 'messages')->assertJsonPath('messages.1.author_type', 'seller');
        $this->withToken($sellerToken)->postJson("/api/seller/support-tickets/{$ticketId}/resolve")->assertOk()->assertJsonPath('status', 'resolved');
        $this->withToken($customerToken)->postJson("/api/customers/support-tickets/{$ticketId}/messages", ['body' => 'Still waiting.'])
            ->assertOk()->assertJsonPath('status', 'open');
        $this->withToken($admin->createToken('test', ['admin:basic'])->plainTextToken)
            ->getJson('/api/admin/support-tickets')->assertOk()->assertJsonPath('total', 1);
        $this->withToken($customerToken)->postJson('/api/customers/support-tickets', [
            'category' => 'Delivery', 'subject' => 'Unsafe', 'body' => 'Test', 'attachments' => ['javascript:alert(1)'],
        ])->assertUnprocessable();
    }
}
