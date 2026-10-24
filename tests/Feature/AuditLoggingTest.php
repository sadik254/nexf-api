<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\PartnerPayoutAccount;
use App\Models\PartnerWithdrawal;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_audits_payment_confirmation_partner_payouts_and_owner_staff_changes_by_permission(): void
    {
        Mail::fake();
        $admin = Admin::create(['name' => 'Admin', 'email' => 'audit-admin@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $adminToken = $admin->createToken('test', ['admin'])->plainTextToken;
        $order = Order::create(['order_number' => 'AUDIT-PAYMENT', 'status' => 'pending', 'payment_status' => 'unpaid', 'payment_method_code' => 'bkash', 'subtotal' => 100, 'total' => 100, 'shipping_name' => 'Buyer', 'shipping_phone' => '01712345678', 'shipping_address' => 'Dhaka']);

        $this->withToken($adminToken)->postJson("/api/admin/orders/{$order->id}", ['paid_amount' => 100])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'permission' => 'payments.confirm']);

        $seller = Seller::create(['seller_name' => 'Payout owner', 'email' => 'audit-seller@example.test', 'store_name' => 'Audit Store', 'store_slug' => 'audit-store', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $account = PartnerPayoutAccount::create(['seller_id' => $seller->id, 'provider' => 'bkash', 'account' => '01700000000', 'is_selected' => true]);
        $withdrawal = PartnerWithdrawal::create(['seller_id' => $seller->id, 'partner_payout_account_id' => $account->id, 'amount' => 500, 'reference' => 'PAY-00001']);
        $this->withToken($adminToken)->postJson("/api/admin/partner-withdrawals/{$withdrawal->id}", ['status' => 'paid', 'transaction_id' => 'AUDIT-TXN'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'permission' => 'withdrawals.settle']);

        $sellerToken = $seller->createToken('test', ['seller'])->plainTextToken;
        $this->withToken($sellerToken)->postJson('/api/seller/staff', ['name' => 'Worker', 'email' => 'audit-worker@example.test'])->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $seller->id, 'permission' => 'staff.manage']);
        $this->assertSame(3, AuditLog::count());
    }
}
