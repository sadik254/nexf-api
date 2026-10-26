<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PartnerWithdrawal;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerPayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_manages_a_payout_account_and_admin_settles_request(): void
    {
        $seller = Seller::create([
            'seller_name' => 'Payout Seller', 'email' => 'payout-seller@example.test', 'store_name' => 'Payout Store',
            'store_slug' => 'payout-store', 'status' => 'approved', 'is_active' => true, 'password' => 'password123',
        ]);
        $sellerToken = $seller->createToken('test', ['seller'])->plainTextToken;
        $account = $this->withToken($sellerToken)->postJson('/api/seller/payout-accounts', [
            'provider' => 'bkash', 'account' => '01700000000',
        ])->assertCreated()->assertJsonPath('isSelected', true)->json('id');
        $this->withToken($sellerToken)->getJson('/api/seller/payouts')->assertOk()
            ->assertJsonPath('rules.min_amount', 500)->assertJsonPath('accounts.0.id', $account);
        $this->withToken($sellerToken)->postJson('/api/seller/payout-accounts', [
            'provider' => 'upay', 'account' => '01800000000',
        ])->assertCreated()->assertJsonPath('provider', 'upay');

        $withdrawal = PartnerWithdrawal::create(['seller_id' => $seller->id, 'partner_payout_account_id' => $account, 'amount' => 500, 'reference' => 'PAY-00001']);
        $admin = Admin::create(['name' => 'Admin', 'email' => 'payout-admin@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $adminToken = $admin->createToken('test', ['admin'])->plainTextToken;
        $this->withToken($adminToken)->postJson("/api/admin/partner-withdrawals/{$withdrawal->id}", [
            'status' => 'paid', 'transaction_id' => 'TXN-1',
        ])->assertOk()->assertJsonPath('status', 'paid')->assertJsonPath('payee.store_name', 'Payout Store');
    }
}
