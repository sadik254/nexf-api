<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_store_chat_is_scoped_and_reuses_the_thread(): void
    {
        $customer = Customer::create(['name' => 'Buyer', 'email' => 'buyer-chat@example.test', 'password' => 'password123']);
        $other = Customer::create(['name' => 'Other', 'email' => 'other-chat@example.test', 'password' => 'password123']);
        $seller = Seller::create(['seller_name' => 'Seller', 'email' => 'seller-chat@example.test', 'store_name' => 'Store', 'store_slug' => 'chat-store', 'kyc_type' => 'nid', 'kyc_number' => '1', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $anotherSeller = Seller::create(['seller_name' => 'Another', 'email' => 'another-chat@example.test', 'store_name' => 'Other Store', 'store_slug' => 'other-chat-store', 'kyc_type' => 'nid', 'kyc_number' => '2', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $admin = Admin::create(['name' => 'Admin', 'email' => 'admin-chat@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $customerToken = $customer->createToken('test', ['customer:basic'])->plainTextToken;
        $id = $this->withToken($customerToken)->postJson('/api/customers/chats', ['seller_id' => $seller->id, 'body' => 'Hello'])->assertOk()->assertJsonPath('messages.0.body', 'Hello')->json('id');
        $this->withToken($customerToken)->postJson('/api/customers/chats', ['seller_id' => $seller->id, 'body' => 'Another question'])->assertOk()->assertJsonPath('id', $id)->assertJsonCount(2, 'messages');
        $this->withToken($other->createToken('test', ['customer:basic'])->plainTextToken)->getJson("/api/customers/chats/{$id}")->assertNotFound();
        $this->withToken($anotherSeller->createToken('test', ['seller:basic'])->plainTextToken)->getJson("/api/seller/chats/{$id}")->assertNotFound();
        $sellerToken = $seller->createToken('test', ['seller:basic'])->plainTextToken;
        $this->withToken($sellerToken)->getJson('/api/seller/chats')->assertOk()->assertJsonPath('total', 1);
        $this->withToken($sellerToken)->postJson("/api/seller/chats/{$id}/messages", ['body' => 'How can we help?'])->assertOk()->assertJsonPath('messages.2.author_type', 'seller');
        $this->withToken($admin->createToken('test', ['admin:basic'])->plainTextToken)->getJson('/api/admin/chats')->assertOk()->assertJsonPath('total', 1);
    }
}
