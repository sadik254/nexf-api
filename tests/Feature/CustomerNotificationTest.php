<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\CustomerInSiteNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Seller;
use App\Services\OrderNotificationService;
use App\Services\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CustomerNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_return_support_and_withdrawal_actions_notify_the_shopper(): void
    {
        $customer = Customer::create(['name' => 'Buyer', 'email' => 'bell-buyer@example.test', 'password' => 'password123']);
        $seller = Seller::create(['seller_name' => 'Seller', 'email' => 'bell-seller@example.test', 'store_name' => 'Store', 'store_slug' => 'bell-shop', 'kyc_type' => 'nid', 'kyc_number' => '1', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $admin = Admin::create(['name' => 'Admin', 'email' => 'bell-admin@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $order = Order::create(['order_number' => 'BELL-1', 'customer_id' => $customer->id, 'status' => 'delivered', 'payment_status' => 'paid', 'payment_method_code' => 'bkash', 'paid_amount' => 100, 'payment_received_at' => now(), 'subtotal' => 100, 'total' => 100, 'shipping_charge' => 0, 'shipping_name' => 'Buyer', 'shipping_phone' => '01700000000', 'shipping_address' => 'Dhaka']);
        $item = OrderItem::create(['order_id' => $order->id, 'seller_id' => $seller->id, 'product_name' => 'Bag', 'quantity' => 1, 'unit_selling_price' => 100, 'unit_buying_price' => 50, 'line_subtotal' => 100, 'line_cost' => 50, 'line_profit' => 50, 'fulfillment_status' => 'delivered']);
        $customerToken = $customer->createToken('test', ['customer:basic'])->plainTextToken;
        $sellerToken = $seller->createToken('test', ['seller'])->plainTextToken;
        $adminToken = $admin->createToken('test', ['admin'])->plainTextToken;

        $returnId = $this->withToken($customerToken)->postJson('/api/customers/return-requests', ['order_item_id' => $item->id, 'type' => 'refund', 'quantity' => 1, 'reason' => 'Damaged'])->assertCreated()->json('id');
        $this->withToken($sellerToken)->postJson("/api/seller/return-requests/{$returnId}", ['status' => 'approved'])->assertOk();
        $this->withToken($sellerToken)->postJson("/api/seller/return-requests/{$returnId}", ['decision_note' => 'Note only'])->assertOk();
        $this->withToken($sellerToken)->postJson("/api/seller/return-requests/{$returnId}", ['status' => 'received'])->assertOk();
        $this->withToken($adminToken)->postJson("/api/admin/return-requests/{$returnId}", ['status' => 'refunded', 'refund_amount' => 100])->assertOk();
        $this->assertSame(['return_approved', 'return_received', 'refund_completed'], CustomerInSiteNotification::orderBy('id')->pluck('type')->all());

        $ticketId = $this->withToken($customerToken)->postJson('/api/customers/support-tickets', ['seller_id' => $seller->id, 'category' => 'Delivery', 'subject' => 'Parcel delayed', 'body' => 'Where is it?'])->assertCreated()->json('id');
        $this->withToken($customerToken)->postJson("/api/customers/support-tickets/{$ticketId}/messages", ['body' => 'Any news?'])->assertOk();
        $this->withToken($sellerToken)->postJson("/api/seller/support-tickets/{$ticketId}/messages", ['body' => 'Checking.'])->assertOk();
        $this->withToken($sellerToken)->postJson("/api/seller/support-tickets/{$ticketId}/resolve")->assertOk();
        $this->assertSame(['support_reply', 'support_resolved'], CustomerInSiteNotification::where('type', 'like', 'support%')->orderBy('id')->pluck('type')->all());
        $this->assertSame('New support reply', CustomerInSiteNotification::where('type', 'support_reply')->value('title'));
        $this->assertSame('SUP-00001 has a new reply.', CustomerInSiteNotification::where('type', 'support_reply')->value('body'));
        $this->assertSame('Support ticket resolved', CustomerInSiteNotification::where('type', 'support_resolved')->value('title'));
        $this->assertSame('SUP-00001 is closed. Reply to reopen it.', CustomerInSiteNotification::where('type', 'support_resolved')->value('body'));

        $paid = $this->withToken($customerToken)->postJson('/api/customers/withdrawals', ['amount' => 60, 'method' => 'bKash', 'account_details' => '01700000000'])->assertCreated()->json('id');
        $rejected = $this->withToken($customerToken)->postJson('/api/customers/withdrawals', ['amount' => 40, 'method' => 'bKash', 'account_details' => '01700000000'])->assertCreated()->json('id');
        $this->withToken($adminToken)->postJson("/api/admin/withdrawals/{$paid}", ['status' => 'paid', 'transaction_id' => 'TX9'])->assertOk();
        $this->withToken($adminToken)->postJson("/api/admin/withdrawals/{$rejected}", ['status' => 'rejected'])->assertOk();
        $this->assertSame('৳60 sent (transaction TX9).', CustomerInSiteNotification::where('type', 'withdraw_paid')->value('body'));
        $this->assertSame('৳40 is back in your NEXF Balance.', CustomerInSiteNotification::where('type', 'withdraw_rejected')->value('body'));

        $this->withToken($customerToken)->getJson('/api/customers/notifications')->assertOk()->assertJsonPath('unread_count', 7);
    }

    public function test_wallet_credit_notifies_once_across_retries(): void
    {
        $customer = Customer::create(['name' => 'Buyer', 'email' => 'bell-wallet@example.test', 'password' => 'password123']);
        $order = Order::create(['order_number' => 'BELL-2', 'customer_id' => $customer->id, 'status' => 'delivered', 'payment_status' => 'paid', 'subtotal' => 500, 'total' => 500, 'shipping_charge' => 0, 'shipping_name' => 'Buyer', 'shipping_phone' => '01700000000', 'shipping_address' => 'Dhaka']);

        app(WalletLedgerService::class)->cashback($customer, $order, 10.5);
        app(WalletLedgerService::class)->cashback($customer, $order, 10.5);

        $notification = CustomerInSiteNotification::sole();
        $this->assertSame('wallet_cashback', $notification->type);
        $this->assertSame('৳10.50 added to your NEXF Balance.', $notification->body);
        $this->assertSame(['order_number' => 'BELL-2', 'url' => '/account/balance'], $notification->data);
    }

    public function test_order_status_notifications_use_the_final_bell_copy(): void
    {
        Mail::fake();
        $customer = Customer::create(['name' => 'Buyer', 'email' => 'bell-status@example.test', 'password' => 'password123']);
        $order = Order::create(['order_number' => 'BELL-STATUS', 'customer_id' => $customer->id, 'status' => 'pending', 'subtotal' => 100, 'total' => 100, 'shipping_charge' => 0, 'shipping_name' => 'Buyer', 'shipping_phone' => '01700000000', 'shipping_address' => 'Dhaka']);

        $expected = [
            'confirmed' => ['Order confirmed', "We're getting it ready."],
            'packed' => ['Order packed', 'Your parcel is ready for the courier.'],
            'shipped' => ['Order shipped', "It's with the courier now."],
            'delivered' => ['Order delivered', 'We hope you enjoy it.'],
            'returning' => ['Return started', "We'll keep you updated."],
            'returned' => ['Return complete', 'Your return has been received.'],
            'unknown_state' => ['Order update', 'Your order is now unknown_state.'],
        ];

        foreach ($expected as $status => [$title, $body]) {
            $order->update(['status' => $status]);
            app(OrderNotificationService::class)->statusChanged($order->fresh());
            $notification = CustomerInSiteNotification::latest('id')->first();
            $this->assertSame($title, $notification->title);
            $this->assertSame($body, $notification->body);
        }
    }

    public function test_opening_a_customer_bell_lazily_prunes_expired_notifications(): void
    {
        $customer = Customer::create(['name' => 'Buyer', 'email' => 'prune-buyer@example.test', 'password' => 'password123']);
        $oldRead = CustomerInSiteNotification::create(['customer_id' => $customer->id, 'type' => 'old_read', 'title' => 'Old read', 'read_at' => now()->subDays(31)]);
        $oldUnread = CustomerInSiteNotification::create(['customer_id' => $customer->id, 'type' => 'old_unread', 'title' => 'Old unread']);
        $oldUnread->forceFill(['created_at' => now()->subDays(91), 'updated_at' => now()->subDays(91)])->save();
        $keep = CustomerInSiteNotification::create(['customer_id' => $customer->id, 'type' => 'keep', 'title' => 'Keep']);

        $this->withToken($customer->createToken('test', ['customer:basic'])->plainTextToken)->getJson('/api/customers/notifications')
            ->assertOk()->assertJsonPath('unread_count', 1);

        $this->assertDatabaseMissing('customer_in_site_notifications', ['id' => $oldRead->id]);
        $this->assertDatabaseMissing('customer_in_site_notifications', ['id' => $oldUnread->id]);
        $this->assertDatabaseHas('customer_in_site_notifications', ['id' => $keep->id]);
    }
}
