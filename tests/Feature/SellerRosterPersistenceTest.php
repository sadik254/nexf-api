<?php
namespace Tests\Feature;
use App\Models\Admin;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
class SellerRosterPersistenceTest extends TestCase {
 use RefreshDatabase;
 public function test_seller_delete_preserves_orders_and_unassigns_products_without_public_house_sale():void {
  Mail::fake();$admin=Admin::create(['name'=>'Archive admin','email'=>'archive-admin@example.test','password'=>'password123','role'=>'admin']);
  $seller=Seller::create(['store_name'=>'Archive store','store_slug'=>'archive-store','email'=>'archive-store@example.test','password'=>'password123','status'=>'approved','is_active'=>true]);
  $category=\App\Models\ProductCategory::create(['name'=>'Archive category','slug'=>'archive-category']);
  $product=\App\Models\Product::create(['category_id'=>$category->id,'seller_id'=>$seller->id,'name'=>'Archive item','slug'=>'archive-item','status'=>'active','product_type'=>'simple','default_selling_price'=>10]);
  $buyer=\App\Models\Customer::create(['name'=>'Archive buyer','email'=>'archive-buyer@example.test','password'=>'password123']);
  $order=\App\Models\Order::create(['customer_id'=>$buyer->id,'order_number'=>'ARCHIVE-HISTORY','status'=>'pending','payment_status'=>'unpaid','subtotal'=>10,'shipping_charge'=>0,'discount_total'=>0,'total'=>10,'shipping_name'=>'Archive buyer','shipping_phone'=>'01700000000','shipping_address'=>'Test address']);
  $item=$order->items()->create(['seller_id'=>$seller->id,'product_id'=>$product->id,'product_name'=>$product->name,'quantity'=>1,'unit_selling_price'=>10,'unit_buying_price'=>0,'line_subtotal'=>10,'line_cost'=>0,'line_profit'=>10,'fulfillment_status'=>'pending']);
  $sellerToken=$seller->createToken('old',['seller:basic']);
  $token=$admin->createToken('test',['admin:basic'])->plainTextToken;
  $this->withToken($token)->postJson('/api/admin/sellers/'.$seller->id.'/delete')->assertOk();
  $this->assertNull($product->fresh()->seller_id);$this->assertTrue($product->fresh()->owner_unassigned);
  $this->assertNotNull($seller->fresh()->roster_archived_at);$this->assertFalse($seller->fresh()->is_active);
  $this->assertDatabaseMissing('personal_access_tokens',['id'=>$sellerToken->accessToken->id]);
  $this->assertEquals($seller->id,$item->fresh()->seller_id);$this->assertSame('Archive store',$item->fresh()->seller->store_name);
  $this->getJson('/api/admin/sellers')->assertOk()->assertJsonCount(0,'data');
  $this->postJson('/api/admin/sellers/'.$seller->id.'/approve')->assertNotFound();
  $this->getJson('/api/store/products/'.$product->slug)->assertNotFound();
  $this->getJson('/api/store/products/'.$product->slug.'/questions')->assertNotFound();
  $payment=\App\Models\PaymentMethod::create(['name'=>'Cash on Delivery','code'=>'cod','is_active'=>true]);
  $shipping=\App\Models\ShippingMethod::create(['name'=>'Inside Dhaka','code'=>'inside','charge'=>20,'currency'=>'BDT','is_active'=>true]);
  $this->withToken($buyer->createToken('test',['customer:basic'])->plainTextToken)->postJson('/api/customers/orders/preview',['payment_method_id'=>$payment->id,'shipping_method_id'=>$shipping->id,'items'=>[['product_id'=>$product->id,'quantity'=>1]]])->assertUnprocessable()->assertJsonPath('errors.items.0','One or more products are unavailable.');
  Mail::assertNothingSent();
 }
 public function test_only_published_owned_reviews_contribute_to_seller_rating():void {
  $admin=Admin::create(['name'=>'Rating admin','email'=>'rating-admin@example.test','password'=>'password123','role'=>'admin']);
  $seller=Seller::create(['store_name'=>'Rating store','store_slug'=>'rating-store','email'=>'rating-store@example.test','password'=>'password123','status'=>'approved','is_active'=>true]);
  $category=\App\Models\ProductCategory::create(['name'=>'Rating category','slug'=>'rating-category']);
  $product=\App\Models\Product::create(['category_id'=>$category->id,'seller_id'=>$seller->id,'name'=>'Rating item','slug'=>'rating-item','status'=>'active','product_type'=>'simple']);
  $buyer=\App\Models\Customer::create(['name'=>'Rating buyer','email'=>'rating-buyer@example.test','password'=>'password123']);
  foreach([['approved',5],['approved',3],['pending',1],['rejected',2]] as [$status,$rating]) \App\Models\Review::create(['product_id'=>$product->id,'customer_id'=>$buyer->id,'rating'=>$rating,'status'=>$status,'comment'=>'Isolated rating']);
  $response=$this->withToken($admin->createToken('test',['admin:basic'])->plainTextToken)->getJson('/api/admin/sellers')->assertOk()->assertJsonPath('data.0.review_count',2);
  $this->assertEquals(4,$response->json('data.0.rating_average'));
  $this->assertEquals(50,$response->json('data.0.positive_rating_percentage'));
  $response->assertJsonPath('data.0.chat_response_percentage',null);
  $other=\App\Models\Customer::create(['name'=>'Other chat buyer','email'=>'other-chat-buyer@example.test','password'=>'password123']);
  foreach([$buyer,$other] as $index=>$customer) {
   $chat=\App\Models\StoreChat::create(['customer_id'=>$customer->id,'seller_id'=>$seller->id,'store_key'=>'seller:'.$seller->id]);
   $chat->messages()->create(['author_type'=>'customer','author_id'=>$customer->id,'body'=>'Real customer question']);
   if($index===0)$chat->messages()->create(['author_type'=>'seller','author_id'=>$seller->id,'body'=>'Real seller reply']);
  }
  $updated=$this->getJson('/api/admin/sellers')->assertOk();$this->assertEquals(50,$updated->json('data.0.chat_response_percentage'));
 }
 public function test_admin_store_fields_persist_without_invented_identity_or_kyc():void {
  Mail::fake();$admin=Admin::create(['name'=>'Roster admin','email'=>'seller-roster@example.test','password'=>'password123','role'=>'admin']);
  $this->withToken($admin->createToken('test',['admin:basic'])->plainTextToken);
  $input=['store_name'=>'Isolated roster store','sku_prefix'=>'ISO1','email'=>'isolated-store@example.test','phone'=>'01712345678','address_line'=>'House 1','address_area'=>'Banani','address_district'=>'Dhaka','commission_rate'=>8];
  $id=$this->postJson('/api/admin/sellers',$input)->assertCreated()->assertJsonPath('sku_prefix','ISO1')->assertJsonPath('commission_rate','8.00')->assertJsonPath('kyc_number',null)->assertJsonPath('seller_name',null)->json('id');
  $seller=Seller::findOrFail($id);$this->assertTrue($seller->is_active);$this->assertNull($seller->email_verified_at);$this->assertSame('House 1, Banani, Dhaka',$seller->store_address);
  $token=$seller->createToken('old',['seller:basic']);$seller->update(['email_verified_at'=>now()]);
  $this->postJson('/api/admin/sellers/'.$id,array_replace($input,['email'=>'changed-store@example.test','commission_rate'=>0]))->assertOk()->assertJsonPath('commission_rate','0.00')->assertJsonPath('email_verified_at',null);
  $this->assertDatabaseMissing('personal_access_tokens',['id'=>$token->accessToken->id]);
  $this->postJson('/api/admin/sellers',array_replace($input,['email'=>'second-store@example.test','phone'=>'01812345678']))->assertUnprocessable()->assertJsonValidationErrors('sku_prefix');
  $moderator=Admin::create(['name'=>'Moderator','email'=>'seller-mod@example.test','password'=>'password123','role'=>'moderator']);
  $this->withToken($moderator->createToken('test',['admin:basic'])->plainTextToken)->postJson('/api/admin/sellers',$input)->assertForbidden();
  Mail::assertNothingSent();
 }
}
