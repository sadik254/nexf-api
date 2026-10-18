<?php
namespace Tests\Feature;
use App\Models\Customer; use App\Models\CustomerVerificationCode; use Illuminate\Foundation\Testing\RefreshDatabase; use Tests\TestCase;
class EmailChangeTest extends TestCase { use RefreshDatabase;
 private Customer $customer;
 protected function setUp(): void { parent::setUp(); $this->customer=Customer::create(['name'=>'Shopper','email'=>'old@example.test','password'=>'password123']); $this->withToken($this->customer->createToken('test',['customer:basic'])->plainTextToken); }
 private function code(string $email,string $type): string { return CustomerVerificationCode::where('email',$email)->where('type',$type)->whereNull('used_at')->latest('id')->value('code'); }
 public function test_email_needs_both_codes_before_it_changes(): void { $this->postJson('/api/customers/me',['email'=>'new@example.test'])->assertUnprocessable()->assertJsonValidationErrors('email'); $this->postJson('/api/customers/me/email/send-current')->assertOk()->assertJsonPath('step','current'); $this->postJson('/api/customers/me/email/verify-current',['code'=>$this->code('old@example.test','email_change_current')])->assertOk()->assertJsonPath('step','enter'); $this->postJson('/api/customers/me/email',['email'=>'new@example.test'])->assertOk()->assertJsonPath('step','new'); $this->assertSame('old@example.test',$this->customer->fresh()->email); $this->postJson('/api/customers/me/email/verify-new',['code'=>$this->code('new@example.test','email_change_new')])->assertOk()->assertJsonPath('customer.email','new@example.test'); $this->assertNull($this->customer->fresh()->pending_email); }
}
