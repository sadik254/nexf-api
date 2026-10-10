<?php
namespace Tests\Feature;
use App\Models\Customer;
use App\Models\Admin;
use App\Models\CustomerWalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class CustomerAccountPersistenceTest extends TestCase {
 use RefreshDatabase;
 private function customer(string $email):Customer{return Customer::create(['name'=>'Account tester','email'=>$email,'password'=>'password123']);}
 public function test_customer_can_remove_only_their_own_profile_picture():void {
  $one=$this->customer('picture-one@example.test');$other=$this->customer('picture-other@example.test');
  $one->update(['profile_picture'=>'https://example.test/one.png']);$other->update(['profile_picture'=>'https://example.test/other.png']);
  $this->withToken($one->createToken('test',['customer:basic'])->plainTextToken);
  $this->postJson('/api/customers/me',['name'=>'Updated name'])->assertOk()->assertJsonPath('customer.profile_picture','https://example.test/one.png');
  $this->postJson('/api/customers/me',['remove_profile_picture'=>true])->assertOk()->assertJsonPath('customer.profile_picture',null);
  $this->assertNull($one->fresh()->profile_picture);$this->assertSame('https://example.test/other.png',$other->fresh()->profile_picture);
 }
 public function test_addresses_are_owned_and_have_one_protected_default():void {
  $one=$this->customer('address-one@example.test');$other=$this->customer('address-other@example.test');
  $this->withToken($one->createToken('test',['customer:basic'])->plainTextToken);
  $input=['label'=>'Home','recipient'=>'Buyer','phone'=>'01700000000','line'=>'House 1','area'=>'Banani','district'=>'Dhaka'];
  $first=$this->postJson('/api/customers/addresses',$input)->assertCreated()->assertJsonPath('isDefault',true)->json('id');
  $second=$this->postJson('/api/customers/addresses',$input+['isDefault'=>false])->assertCreated()->assertJsonPath('isDefault',false)->json('id');
  $this->postJson('/api/customers/addresses/'.$first.'/delete')->assertUnprocessable();
  $this->postJson('/api/customers/addresses/'.$second.'/default')->assertOk();
  $this->getJson('/api/customers/addresses')->assertOk()->assertJsonCount(2)->assertJsonPath('0.id',$second)->assertJsonPath('1.isDefault',false);
  $this->postJson('/api/customers/addresses/'.$first.'/delete')->assertOk();
  $this->withToken($other->createToken('test',['customer:basic'])->plainTextToken)->getJson('/api/customers/addresses')->assertExactJson([]);
  $this->postJson('/api/customers/addresses/'.$second,$input)->assertNotFound();
  $this->postJson('/api/customers/addresses/'.$second.'/default')->assertNotFound();
 }
 public function test_payout_snapshot_and_wallet_reservations_are_real_and_scoped():void {
  $one=$this->customer('payout-one@example.test');$other=$this->customer('payout-other@example.test');
  $token=$one->createToken('test',['customer:basic'])->plainTextToken;
  $this->withToken($token);
  CustomerWalletTransaction::create(['customer_id'=>$one->id,'type'=>'credit','amount'=>100,'description'=>'Isolated refund']);
  $payout=$this->postJson('/api/customers/payout-accounts',['provider'=>'bkash','account'=>'01700000000'])->assertCreated()->assertJsonPath('isSelected',true)->json('id');
  $this->postJson('/api/customers/payout-accounts',['provider'=>'bank','account'=>'123456'])->assertUnprocessable();
  $this->getJson('/api/customers/wallet')->assertOk()->assertJsonPath('balance',100)->assertJsonCount(1,'transactions');
  $withdrawal=$this->postJson('/api/customers/withdrawals',['payout_account_id'=>$payout,'amount'=>100,'method'=>'tampered','account_details'=>'tampered'])->assertCreated()->assertJsonPath('method','bkash');
  $id=$withdrawal->json('id');$this->assertStringContainsString('01700000000',$withdrawal->json('account_details'));
  $this->postJson('/api/customers/withdrawals',['payout_account_id'=>$payout,'amount'=>1,'method'=>'bkash','account_details'=>'01700000000'])->assertUnprocessable();
  $this->postJson('/api/customers/payout-accounts/'.$payout,['provider'=>'bkash','account'=>'01800000000'])->assertOk();
  $this->assertDatabaseHas('withdrawal_requests',['id'=>$id,'account_details'=>$withdrawal->json('account_details')]);
  $this->withToken($other->createToken('test',['customer:basic'])->plainTextToken)->getJson('/api/customers/payout-accounts')->assertExactJson([]);
  $this->postJson('/api/customers/payout-accounts/'.$payout.'/delete')->assertNotFound();
  $this->postJson('/api/customers/withdrawals',['payout_account_id'=>$payout,'amount'=>1,'method'=>'bkash','account_details'=>'01700000000'])->assertNotFound();
  $admin=Admin::create(['name'=>'Admin','email'=>'payout-admin@example.test','password'=>'password123','role'=>'super_admin']);
  $this->withToken($admin->createToken('test',['admin:basic'])->plainTextToken)->postJson('/api/admin/withdrawals/'.$id,['status'=>'rejected'])->assertOk();
  $this->postJson('/api/admin/withdrawals/'.$id,['status'=>'rejected'])->assertUnprocessable();
  $this->withToken($token)->getJson('/api/customers/wallet')->assertOk()->assertJsonPath('balance',100)->assertJsonPath('earned',100)->assertJsonPath('spent',0);
 }
}
