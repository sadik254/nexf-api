<?php
namespace Tests\Feature;

use App\Http\Controllers\OrderController;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Review;
use App\Services\WalletRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletRewardTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_awards_cashback_and_first_referral_reward_once(): void
    {
        $referrer = $this->customer('referrer@example.test');
        $customer = $this->customer('referred@example.test', $referrer->id);
        $order = $this->order($customer, 'REWARD-1', 'delivered');
        app(WalletRewardService::class)->orderDelivered($order);
        app(WalletRewardService::class)->orderDelivered($order);

        $this->assertDatabaseHas('customer_wallet_transactions', ['customer_id'=>$customer->id, 'kind'=>'cashback', 'amount'=>2]);
        $this->assertDatabaseHas('customer_wallet_transactions', ['customer_id'=>$referrer->id, 'kind'=>'referral_reward', 'amount'=>50]);
        $this->assertSame(2, CustomerWalletTransaction::count());
    }

    public function test_approved_review_awards_once(): void
    {
        $customer = $this->customer('reviewer@example.test');
        $order = $this->order($customer, 'REWARD-2', 'delivered');
        $category = ProductCategory::create(['name'=>'Category','slug'=>'reward-category']);
        $product = Product::create(['category_id'=>$category->id,'name'=>'Product','slug'=>'reward-product','product_type'=>'simple','status'=>'active','default_selling_price'=>100]);
        $item = OrderItem::create(['order_id'=>$order->id,'product_id'=>$product->id,'product_name'=>'Product','quantity'=>1,'unit_selling_price'=>100,'unit_buying_price'=>50,'line_subtotal'=>100,'line_cost'=>50,'line_profit'=>50,'fulfillment_status'=>'delivered']);
        $review = Review::create(['product_id'=>$product->id,'customer_id'=>$customer->id,'order_item_id'=>$item->id,'rating'=>5,'status'=>'approved']);
        app(WalletRewardService::class)->reviewApproved($review);
        app(WalletRewardService::class)->reviewApproved($review);
        $this->assertDatabaseHas('customer_wallet_transactions', ['customer_id'=>$customer->id, 'kind'=>'review_reward', 'amount'=>20]);
        $this->assertSame(1, CustomerWalletTransaction::count());
    }

    private function customer(string $email, ?int $referrerId=null): Customer { return Customer::create(['name'=>'Customer','email'=>$email,'password'=>'password123','email_verified_at'=>now(),'referred_by_customer_id'=>$referrerId]); }
    private function order(Customer $customer,string $number,string $status): Order { return Order::create(['order_number'=>$number,'customer_id'=>$customer->id,'status'=>$status,'payment_status'=>'paid','subtotal'=>100,'total'=>100,'shipping_charge'=>0,'shipping_name'=>'Customer','shipping_phone'=>'01712345678','shipping_address'=>'Dhaka']); }
}
