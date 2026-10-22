<?php
namespace Tests\Feature;
use App\Models\Admin;use Illuminate\Foundation\Testing\RefreshDatabase;use Tests\TestCase;
class HouseStoreTest extends TestCase {use RefreshDatabase;
 public function test_super_admin_can_save_house_store_details():void{$admin=Admin::create(['name'=>'Root','email'=>'root@example.test','password'=>'password123','role'=>'super_admin']);$token=$admin->createToken('test',['admin:basic'])->plainTextToken;$data=['store_name'=>'NEXF Lifestyle','sku_prefix'=>'NEXF','email'=>'support@example.test','phone'=>'01712345678','address_line'=>'1 Road','address_area'=>'Gulshan','address_district'=>'Dhaka'];$this->withToken($token)->postJson('/api/admin/house-store',$data)->assertOk()->assertJsonPath('store_name','NEXF Lifestyle')->assertJsonPath('joined_at',now()->toDateString());$this->withToken($token)->getJson('/api/admin/house-store')->assertOk()->assertJsonPath('sku_prefix','NEXF');}
}
