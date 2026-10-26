<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Seller;
use App\Models\Reseller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellerStaff;
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

    public function test_reseller_support_tickets_are_persisted_and_scoped_to_the_owner(): void
    {
        $reseller = Reseller::create(['name' => 'Partner', 'email' => 'partner-ticket@example.test', 'password' => 'password123']);
        $other = Reseller::create(['name' => 'Other', 'email' => 'other-ticket@example.test', 'password' => 'password123']);
        $token = $reseller->createToken('test', ['reseller:basic'])->plainTextToken;
        $ticketId = $this->withToken($token)->postJson('/api/resellers/support-tickets', [
            'category' => 'Account', 'subject' => 'Commission question', 'body' => 'Please explain this month’s target.',
        ])->assertCreated()->assertJsonPath('reseller_id', $reseller->id)->assertJsonPath('messages.0.author_type', 'reseller')->json('id');
        $this->withToken($token)->getJson('/api/resellers/support-tickets')->assertOk()->assertJsonPath('total', 1);
        $this->withToken($other->createToken('test', ['reseller:basic'])->plainTextToken)
            ->getJson("/api/resellers/support-tickets/{$ticketId}")->assertNotFound();
        $this->withToken($token)->postJson("/api/resellers/support-tickets/{$ticketId}/messages", ['body' => 'Following up.'])
            ->assertOk()->assertJsonCount(2, 'messages')->assertJsonPath('messages.1.author_type', 'reseller');
    }

    public function test_console_staff_can_open_customer_and_guest_tickets_with_the_right_store_scope(): void
    {
        $customer = Customer::create(['name' => 'Suspended buyer', 'email' => 'suspended-ticket@example.test', 'phone' => '01766666666', 'password' => 'password123', 'crm_status' => 'suspended']);
        $seller = Seller::create(['seller_name' => 'Seller', 'email' => 'console-seller@example.test', 'store_name' => 'Console Store', 'store_slug' => 'console-store', 'kyc_type' => 'nid', 'kyc_number' => '11', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $otherSeller = Seller::create(['seller_name' => 'Other', 'email' => 'console-other@example.test', 'store_name' => 'Other Store', 'store_slug' => 'console-other-store', 'kyc_type' => 'nid', 'kyc_number' => '12', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $admin = Admin::create(['name' => 'Admin', 'email' => 'console-ticket-admin@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);

        $this->withToken($admin->createToken('test', ['admin:basic'])->plainTextToken)
            ->postJson('/api/admin/support-tickets', ['customer_id' => $customer->id, 'no_store' => true, 'category' => 'Account', 'subject' => 'Account help', 'body' => 'Opened by the support team.'])
            ->assertCreated()->assertJsonPath('customer_id', $customer->id)->assertJsonPath('no_store', true)->assertJsonPath('seller_id', null)->assertJsonPath('reference', 'SUP-00001')->assertJsonPath('messages.0.author_type', 'admin');

        $guest = $this->withToken($admin->createToken('test2', ['admin:basic'])->plainTextToken)
            ->postJson('/api/admin/support-tickets', ['guest_name' => 'Guest caller', 'guest_phone' => '01777777777', 'guest_email' => 'caller@example.test', 'seller_id' => $seller->id, 'no_store' => true, 'category' => 'Delivery', 'subject' => 'Delivery issue', 'body' => 'Opened for a caller.'])
            ->assertCreated()->assertJsonPath('guest_phone', '01777777777')->assertJsonPath('seller_id', $seller->id)->assertJsonPath('no_store', false);

        $order = Order::create(['order_number' => 'SUP-ORDER', 'status' => 'pending', 'subtotal' => 100, 'total' => 100, 'shipping_name' => 'Buyer', 'shipping_phone' => '01766666666', 'shipping_address' => 'Dhaka']);
        OrderItem::create(['order_id' => $order->id, 'seller_id' => $seller->id, 'product_name' => 'Store item', 'quantity' => 1, 'unit_selling_price' => 100, 'unit_buying_price' => 50, 'line_subtotal' => 100, 'line_cost' => 50, 'line_profit' => 50]);
        $staff = SellerStaff::create(['seller_id' => $seller->id, 'name' => 'Store helper', 'email' => 'store-helper@example.test', 'password' => 'password123', 'status' => 'active']);
        $staffToken = $staff->createToken('test', ['seller:basic'])->plainTextToken;
        $this->withToken($staffToken)->postJson('/api/seller/support-tickets', ['guest_name' => 'Guest', 'guest_phone' => '01788888888', 'order_id' => $order->id, 'category' => 'Order', 'subject' => 'Parcel query', 'body' => 'Opened by store staff.'])
            ->assertCreated()->assertJsonPath('seller_id', $seller->id)->assertJsonPath('messages.0.author_type', 'seller_staff')->assertJsonPath('messages.0.author_name', 'Store helper');

        $otherOrder = Order::create(['order_number' => 'OTHER-ORDER', 'status' => 'pending', 'subtotal' => 100, 'total' => 100, 'shipping_name' => 'Buyer', 'shipping_phone' => '01766666666', 'shipping_address' => 'Dhaka']);
        OrderItem::create(['order_id' => $otherOrder->id, 'seller_id' => $otherSeller->id, 'product_name' => 'Other item', 'quantity' => 1, 'unit_selling_price' => 100, 'unit_buying_price' => 50, 'line_subtotal' => 100, 'line_cost' => 50, 'line_profit' => 50]);
        $this->withToken($staffToken)->postJson('/api/seller/support-tickets', ['guest_name' => 'Guest', 'guest_phone' => '01788888888', 'order_id' => $otherOrder->id, 'category' => 'Order', 'subject' => 'Not ours', 'body' => 'This must not be created.'])
            ->assertUnprocessable()->assertJsonValidationErrors('order_id');
    }
}
