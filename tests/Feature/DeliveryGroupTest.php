<?php
namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\ProductCategory;
use App\Models\Seller;
use App\Models\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_delivery_charges_once_at_the_main_stores_rate(): void
    {
        [$admin, $customer, $first, $second, $one, $two, $shipping, $payment] = $this->fixtures();
        $token = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $group = $this->withToken($token)->postJson('/api/admin/delivery-groups', [
            'name' => 'Owner stores', 'seller_ids' => [$first->id, $second->id],
            'includes_house' => false, 'ships_from_seller_id' => $first->id,
        ])->assertCreated()->assertJsonPath('ships_from_seller_id', $first->id)->json();

        $customerToken = $customer->createToken('test', ['customer:basic'])->plainTextToken;
        $payload = ['items'=>[['product_id'=>$one->id,'quantity'=>1],['product_id'=>$two->id,'quantity'=>1]], 'payment_method_id'=>$payment->id, 'shipping_method_id'=>$shipping->id];
        $this->withToken($customerToken)->postJson('/api/customers/orders/preview', $payload)
            ->assertOk()->assertJsonCount(1, 'shipping_groups')->assertJsonPath('shipping_charge', 20)
            ->assertJsonPath('shipping_groups.0.seller_id', $first->id);

        $secondToken = $second->createToken('test', ['seller:basic'])->plainTextToken;
        $this->withToken($secondToken)->postJson('/api/seller/delivery-rates', ['rates'=>[['shipping_method_id'=>$shipping->id,'charge'=>5]]])->assertStatus(422);
        $this->withToken($token)->postJson('/api/admin/delivery-groups/'.$group['id'].'/delete')->assertOk();
        $this->withToken($customerToken)->postJson('/api/customers/orders/preview', $payload)->assertOk()->assertJsonCount(2, 'shipping_groups')->assertJsonPath('shipping_charge', 40);
    }

    private function fixtures(): array
    {
        $admin=Admin::create(['name'=>'Admin','email'=>'admin@example.test','password'=>'password123','role'=>'super_admin']);
        $customer=Customer::create(['name'=>'Customer','email'=>'customer@example.test','password'=>'password123','email_verified_at'=>now()]);
        $makeSeller=function(string $suffix) { return Seller::create(['seller_name'=>$suffix,'email'=>strtolower($suffix).'@example.test','store_name'=>$suffix.' Store','store_slug'=>strtolower($suffix).'-store','kyc_type'=>'nid','kyc_number'=>$suffix,'kyc_document_url'=>'https://example.test/id','product_category'=>'General','status'=>'approved','is_active'=>true,'password'=>'password123']);};
        $first=$makeSeller('First'); $second=$makeSeller('Second');
        $category=ProductCategory::create(['name'=>'Category','slug'=>'category']);
        $makeProduct=function(Seller $seller,string $name) use ($category) { $product=Product::create(['seller_id'=>$seller->id,'category_id'=>$category->id,'name'=>$name,'slug'=>strtolower(str_replace(' ','-',$name)),'product_type'=>'simple','status'=>'active','default_selling_price'=>100]); ProductLot::create(['product_id'=>$product->id,'lot_number'=>$name,'buying_price'=>50,'selling_price'=>100,'quantity'=>2,'quantity_remaining'=>2]); return $product;};
        $one=$makeProduct($first,'First item');$two=$makeProduct($second,'Second item');
        $shipping=ShippingMethod::create(['name'=>'Inside Dhaka','code'=>'inside_dhaka','charge'=>20,'currency'=>'BDT','is_active'=>true]);
        $payment=PaymentMethod::create(['name'=>'Cash on Delivery','code'=>'cod','is_active'=>true]);
        return [$admin, $customer, $first, $second, $one, $two, $shipping, $payment];
    }
}
