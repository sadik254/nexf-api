<?php
namespace Tests\Feature;
use App\Models\Admin;
use App\Models\Seller;
use App\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class ReferencePersistenceTest extends TestCase {
 use RefreshDatabase;
 public function test_guest_contact_is_persisted_and_not_publicly_readable(): void {
  $response=$this->postJson('/api/contact',['name'=>'Guest','email'=>'guest@example.test','subject'=>'Delivery question','message'=>'Please help with delivery.'])->assertCreated();
  $id=$response->json('id');
  $this->assertDatabaseHas('support_tickets',['id'=>$id,'guest_email'=>'guest@example.test','customer_id'=>null]);
  $this->assertDatabaseHas('support_messages',['support_ticket_id'=>$id,'author_type'=>'guest','body'=>'Please help with delivery.']);
  $this->getJson("/api/admin/support-tickets/{$id}")->assertUnauthorized();
  $this->postJson('/api/contact',['name'=>'Guest'])->assertUnprocessable();
  $admin=Admin::create(['name'=>'Admin','email'=>'contact-admin@example.test','password'=>'password123','role'=>'super_admin','is_active'=>true]);
  $this->withToken($admin->createToken('test',['admin:basic'])->plainTextToken)->getJson("/api/admin/support-tickets/{$id}")->assertOk()->assertJsonPath('guest_email','guest@example.test')->assertJsonPath('messages.0.body','Please help with delivery.');
 }
 public function test_banner_media_cta_and_theme_persist_and_invalid_links_are_rejected(): void {
  $admin=Admin::create(['name'=>'Admin','email'=>'banner-admin@example.test','password'=>'password123','role'=>'super_admin','is_active'=>true]);
  MediaAsset::create(['owner_type'=>'admin','owner_id'=>$admin->id,'source'=>'link','url'=>'https://example.test/banner.png','file_name'=>'banner.png','mime_type'=>'image/png']);
  $this->withToken($admin->createToken('test',['admin:basic'])->plainTextToken);
  $response=$this->postJson('/api/admin/homepage/banners',['placement'=>'hero','image'=>'https://example.test/banner.png','href'=>'/shop','cta'=>'Shop now','theme'=>'rose'])->assertCreated();
  $id=$response->json('banner.id');
  $this->getJson('/api/homepage/banners')->assertOk()->assertJsonPath('0.cta','Shop now')->assertJsonPath('0.theme','rose');
  $this->postJson("/api/admin/homepage/banners/{$id}",['image'=>'https://example.test/banner.png','cta'=>'Explore'])->assertOk()->assertJsonPath('banner.cta','Explore');
  $this->postJson("/api/admin/homepage/banners/{$id}",['image'=>'https://example.test/unowned.png'])->assertUnprocessable();
  $this->postJson("/api/admin/homepage/banners/{$id}",['href'=>'javascript:alert(1)'])->assertUnprocessable();
 }
 public function test_homepage_picked_sources_preserve_order_and_exclude_nonpublic_products(): void {
  $category=\App\Models\ProductCategory::create(['name'=>'Home test','slug'=>'home-test','is_active'=>true]);
  $one=\App\Models\Product::create(['category_id'=>$category->id,'name'=>'One','slug'=>'home-one','product_type'=>'simple','status'=>'active','default_selling_price'=>100]);
  $two=\App\Models\Product::create(['category_id'=>$category->id,'name'=>'Two','slug'=>'home-two','product_type'=>'simple','status'=>'active','default_selling_price'=>200]);
  $draft=\App\Models\Product::create(['category_id'=>$category->id,'name'=>'Draft','slug'=>'home-draft','product_type'=>'simple','status'=>'draft']);
  $admin=Admin::create(['name'=>'Admin','email'=>'home-test@example.test','password'=>'password123','role'=>'super_admin','is_active'=>true]);
  $this->withToken($admin->createToken('test',['admin:basic'])->plainTextToken)->postJson('/api/admin/homepage/layout',['sections'=>[
   ['id'=>'picked','type'=>'trending','enabled'=>true,'topSource'=>'picked','topProductIds'=>[$two->id,$draft->id,$one->id],'showNewArrivals'=>false,'limit'=>4],
   ['id'=>'manual','type'=>'collection','enabled'=>true,'source'=>'products','productIds'=>[$one->id],'autoplay'=>true],
   ['id'=>'disabled','type'=>'featured','enabled'=>false,'productIds'=>[$one->id]],
   ['id'=>'support','type'=>'support','enabled'=>true,'support'=>['title'=>'Need help','messenger'=>'https://example.test/help']],
  ]])->assertOk()->assertJsonPath('1.autoplay',true)->assertJsonPath('3.support.title','Need help');
  $this->getJson('/api/homepage/layout/picked/products')->assertOk()->assertJsonCount(2)->assertJsonPath('0.id',$two->id)->assertJsonPath('1.id',$one->id);
  $this->getJson('/api/homepage/layout/picked/products?tab=new')->assertOk()->assertExactJson([]);
  $this->getJson('/api/homepage/layout/manual/products')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id',$one->id);
  $this->getJson('/api/homepage/layout/disabled/products')->assertNotFound();
  $this->getJson('/api/store/marketplace-stats')->assertOk()->assertJsonPath('products',2)->assertJsonPath('districts',null);
 }
 public function test_selected_testimonials_preserve_order_and_hide_unavailable_products(): void {
  $category=\App\Models\ProductCategory::create(['name'=>'Review test','slug'=>'review-test','is_active'=>true]);
  $product=\App\Models\Product::create(['category_id'=>$category->id,'name'=>'Visible','slug'=>'review-visible','product_type'=>'simple','status'=>'active']);
  $draft=\App\Models\Product::create(['category_id'=>$category->id,'name'=>'Hidden','slug'=>'review-hidden','product_type'=>'simple','status'=>'draft']);
  $customer=\App\Models\Customer::create(['name'=>'Review customer','email'=>'review@example.test','password'=>'password123']);
  $one=\App\Models\Review::create(['customer_id'=>$customer->id,'product_id'=>$product->id,'rating'=>4,'comment'=>'First','status'=>'approved']);
  $two=\App\Models\Review::create(['customer_id'=>$customer->id,'product_id'=>$product->id,'rating'=>5,'comment'=>'Second','status'=>'approved']);
  $hidden=\App\Models\Review::create(['customer_id'=>$customer->id,'product_id'=>$draft->id,'rating'=>5,'comment'=>'Hidden','status'=>'approved']);
  $this->getJson('/api/store/testimonials?ids='.$two->id.','.$hidden->id.','.$one->id)->assertOk()->assertJsonCount(2)->assertJsonPath('0.id',$two->id)->assertJsonPath('1.id',$one->id)->assertJsonPath('0.verified',false);
 }
 public function test_collection_deletion_cleans_discount_targets_without_broadening_them(): void {
  $admin=Admin::create(['name'=>'Admin','email'=>'col-cleanup@example.test','password'=>'password123','role'=>'super_admin','is_active'=>true]);
  $collection=\App\Models\ProductCollection::create(['name'=>'Target collection']);
  $remaining=\App\Models\ProductCollection::create(['name'=>'Keep collection']);
  $only=\App\Models\Coupon::create(['code'=>'ONLY','discount_type'=>'fixed','discount_value'=>10,'discount_kind'=>'products','eligible_collection_ids'=>[$collection->id],'is_active'=>true]);
  $mixed=\App\Models\Coupon::create(['code'=>'MIXED','discount_type'=>'fixed','discount_value'=>10,'discount_kind'=>'bxgy','eligible_collection_ids'=>[$collection->id,$remaining->id],'buy_collection_ids'=>[$collection->id,$remaining->id],'is_active'=>true]);
  $this->withToken($admin->createToken('test',['admin:basic'])->plainTextToken)->postJson("/api/admin/collections/{$collection->id}/delete")->assertOk();
  $this->assertSame([], $only->fresh()->eligible_collection_ids); $this->assertFalse($only->fresh()->is_active);
  $this->assertSame([$remaining->id],$mixed->fresh()->eligible_collection_ids); $this->assertSame([$remaining->id],$mixed->fresh()->buy_collection_ids); $this->assertTrue($mixed->fresh()->is_active);
 }
 public function test_seller_self_profile_cannot_change_another_seller_or_privileged_fields(): void {
  $seller=Seller::create(['kyc_type'=>'nid','kyc_number'=>'fixture-a','kyc_document_url'=>'https://example.test/id','product_category'=>'Clothing','seller_name'=>'Seller','email'=>'profile-seller@example.test','phone'=>'0123456789','store_name'=>'Shop','store_slug'=>'profile-shop','password'=>'password123','status'=>'approved','is_active'=>true,'seller_image'=>'https://example.test/avatar.png']);
  $other=Seller::create(['kyc_type'=>'nid','kyc_number'=>'fixture-b','kyc_document_url'=>'https://example.test/id','product_category'=>'Clothing','seller_name'=>'Other','email'=>'other-profile@example.test','store_name'=>'Other shop','store_slug'=>'other-profile','password'=>'password123','status'=>'approved','is_active'=>true]);
  $this->withToken($seller->createToken('test',['seller:basic'])->plainTextToken)->postJson('/api/sellers/me',['seller_name'=>' Updated ', 'email'=>'UPDATED@example.test','phone'=>null,'clear_image'=>true,'status'=>'rejected','id'=>$other->id])->assertOk()->assertJsonPath('seller.seller_name','Updated')->assertJsonPath('seller.email','updated@example.test')->assertJsonPath('seller.seller_image',null)->assertJsonPath('seller.status','approved');
  $this->assertDatabaseHas('sellers',['id'=>$other->id,'kyc_type'=>'nid','kyc_number'=>'fixture-b','kyc_document_url'=>'https://example.test/id','product_category'=>'Clothing','seller_name'=>'Other']);
  $this->postJson('/api/sellers/me',['email'=>$other->email])->assertUnprocessable();
  $this->postJson('/api/sellers/me',['seller_name'=>'  '])->assertUnprocessable();
 }
}
