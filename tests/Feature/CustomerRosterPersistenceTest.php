<?php
namespace Tests\Feature;
use App\Models\Admin;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class CustomerRosterPersistenceTest extends TestCase {
 use RefreshDatabase;
 private function adminToken():string {$a=Admin::create(['name'=>'Roster manager','email'=>'roster-admin@example.test','password'=>'password123','role'=>'admin']);return $a->createToken('test',['admin:customers'])->plainTextToken;}
 public function test_phone_only_contacts_persist_without_fabricated_email_or_order_history():void {
  $this->withToken($this->adminToken());
  $input=['name'=>'Phone-only contact','phone'=>'01711111111','email'=>null,'street'=>'House 1','area'=>'Banani','district'=>'Dhaka','crm_status'=>'new'];
  $id=$this->postJson('/api/admin/customers',$input+['orders_count'=>999,'total_spent'=>100000])->assertCreated()->assertJsonPath('email',null)->assertJsonPath('email_verified_at',null)->json('id');
  $this->getJson('/api/admin/customers')->assertOk()->assertJsonPath('data.0.orders_count',0)->assertJsonPath('data.0.received_orders_count',0)->assertJsonPath('data.0.contact_area','Banani');
  $this->postJson('/api/admin/customers/'.$id,array_replace($input,['crm_status'=>'vip','street'=>'House 2']))->assertOk()->assertJsonPath('contact_street','House 2')->assertJsonPath('crm_status','vip');
  $this->postJson('/api/admin/customers',$input)->assertUnprocessable();
  $this->postJson('/api/admin/customers/'.$id.'/delete')->assertOk();
  $this->getJson('/api/admin/customers')->assertOk()->assertJsonCount(0,'data');
  $this->assertDatabaseHas('customers',['id'=>$id,'phone'=>'01711111111']);
  $this->postJson('/api/admin/customers/'.$id,$input)->assertNotFound();
 }
 public function test_roster_mutations_require_the_existing_customers_ability():void {
  $c=Customer::create(['name'=>'Buyer','email'=>'roster-buyer@example.test','password'=>'password123']);
  $this->withToken($c->createToken('test',['customer:basic'])->plainTextToken)->postJson('/api/admin/customers',[])->assertForbidden();
  $a=Admin::create(['name'=>'No customer scope','email'=>'roster-other@example.test','password'=>'password123','role'=>'admin']);
  $this->withToken($a->createToken('test',['admin:basic'])->plainTextToken)->postJson('/api/admin/customers',[])->assertForbidden();
 }
 public function test_changed_contact_email_removes_verification_and_old_sessions():void {
  $c=Customer::create(['name'=>'Buyer','email'=>'old-contact@example.test','phone'=>'01722222222','password'=>'password123']);$c->forceFill(['email_verified_at'=>now()])->save();$c->createToken('old',['customer:basic']);
  $this->withToken($this->adminToken())->postJson('/api/admin/customers/'.$c->id,['name'=>'Buyer','phone'=>$c->phone,'email'=>'new-contact@example.test','crm_status'=>'active'])->assertOk()->assertJsonPath('email_verified_at',null);
  $this->assertSame(0,$c->tokens()->count());
 }
}
