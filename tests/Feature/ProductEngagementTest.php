<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\ProductCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Review;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductEngagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_product_exposes_specifications_and_customer_can_ask_question(): void
    {
        [$customer, $product] = $this->fixtures();

        $this->getJson("/api/store/products/{$product->slug}")
            ->assertOk()
            ->assertJsonPath('specifications.0.label', 'Material')
            ->assertJsonPath('specifications.0.value', 'Cotton');

        $token = $customer->createToken('test', ['customer:basic'])->plainTextToken;
        $question = $this->withToken($token)
            ->postJson("/api/customers/products/{$product->slug}/questions", ['question' => 'Does this run true to size?'])
            ->assertCreated()
            ->assertJsonPath('question.customer_name', 'Customer')
            ->json('question');

        $this->getJson("/api/store/products/{$product->slug}/questions")
            ->assertOk()
            ->assertJsonPath('data.0.id', $question['id'])
            ->assertJsonPath('data.0.question', 'Does this run true to size?');

        $this->withToken($token)->getJson('/api/customers/questions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $question['id'])
            ->assertJsonPath('data.0.product.slug', $product->slug);
    }

    public function test_review_requires_a_delivered_purchase(): void
    {
        [$customer, $product] = $this->fixtures();
        $token = $customer->createToken('test', ['customer:basic'])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/customers/products/{$product->slug}/reviews", ['rating' => 5, 'comment' => 'Excellent.'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only customers with a delivered purchase can review this product.');

        $order = Order::create([
            'order_number' => 'ORD-REVIEW-1', 'customer_id' => $customer->id,
            'status' => 'delivered', 'payment_status' => 'paid', 'subtotal' => 100,
            'total' => 100, 'shipping_name' => 'Customer', 'shipping_phone' => '01700000000',
            'shipping_address' => 'Dhaka',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'product_slug' => $product->slug,
            'quantity' => 1, 'unit_selling_price' => 100, 'unit_buying_price' => 50,
            'line_subtotal' => 100, 'line_cost' => 50, 'line_profit' => 50,
            'fulfillment_status' => 'delivered',
        ]);

        $this->withToken($token)->getJson('/api/customers/reviewable-items')
            ->assertOk()->assertJsonPath('data.0.id', $item->id);
        $this->withToken($token)->postJson("/api/customers/products/{$product->slug}/reviews", [
            'order_item_id' => $item->id, 'rating' => 5, 'comment' => 'Excellent.',
        ])->assertCreated();
        $this->withToken($token)->getJson('/api/customers/reviews')
            ->assertOk()->assertJsonPath('data.0.product.slug', $product->slug)
            ->assertJsonPath('data.0.status', 'pending');
    }

    public function test_review_moderation_is_scoped_to_the_product_owner(): void
    {
        [$customer, $houseProduct] = $this->fixtures();
        $seller = Seller::create([
            'seller_name' => 'Seller', 'email' => 'review-seller@example.test',
            'store_name' => 'Review Store', 'store_slug' => 'review-store',
            'kyc_type' => 'nid', 'kyc_number' => '123',
            'kyc_document_url' => 'https://example.test/kyc', 'product_category' => 'Clothing',
            'status' => 'approved', 'is_active' => true, 'password' => 'password123',
        ]);
        $sellerProduct = Product::create([
            'seller_id' => $seller->id, 'category_id' => $houseProduct->category_id,
            'name' => 'Seller Shirt', 'slug' => 'seller-shirt',
            'product_type' => 'simple', 'status' => 'active',
        ]);
        $houseReview = Review::create(['product_id' => $houseProduct->id, 'customer_id' => $customer->id, 'rating' => 5, 'comment' => 'House review', 'status' => 'pending']);
        $sellerReview = Review::create(['product_id' => $sellerProduct->id, 'customer_id' => $customer->id, 'rating' => 4, 'comment' => 'Seller review', 'status' => 'pending']);
        $admin = Admin::create(['name' => 'Admin', 'email' => 'review-admin@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);

        $adminToken = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $this->withToken($adminToken)->getJson('/api/admin/product-reviews')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $houseReview->id);
        $this->withToken($adminToken)->postJson("/api/admin/product-reviews/{$sellerReview->id}/moderate", ['status' => 'approved'])
            ->assertForbidden();
        $this->withToken($adminToken)->postJson("/api/admin/product-reviews/{$houseReview->id}/moderate", ['status' => 'approved'])
            ->assertOk();

        $sellerToken = $seller->createToken('test', ['seller:basic'])->plainTextToken;
        $this->withToken($sellerToken)->getJson('/api/seller/product-reviews')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sellerReview->id);
        $this->withToken($sellerToken)->postJson("/api/seller/product-reviews/{$houseReview->id}/moderate", ['status' => 'rejected'])
            ->assertForbidden();
        $this->withToken($sellerToken)->postJson("/api/seller/product-reviews/{$sellerReview->id}/moderate", ['status' => 'approved'])
            ->assertOk();

        $this->assertDatabaseHas('reviews', ['id' => $houseReview->id, 'status' => 'approved']);
        $this->assertDatabaseHas('reviews', ['id' => $sellerReview->id, 'status' => 'approved']);
    }

    public function test_question_inboxes_are_scoped_to_the_product_owner(): void
    {
        [$customer, $houseProduct] = $this->fixtures();
        $seller = Seller::create([
            'seller_name' => 'Seller', 'email' => 'question-seller@example.test',
            'store_name' => 'Question Store', 'store_slug' => 'question-store',
            'kyc_type' => 'nid', 'kyc_number' => '456',
            'kyc_document_url' => 'https://example.test/kyc', 'product_category' => 'Clothing',
            'status' => 'approved', 'is_active' => true, 'password' => 'password123',
        ]);
        $sellerProduct = Product::create([
            'seller_id' => $seller->id, 'category_id' => $houseProduct->category_id,
            'name' => 'Seller Trousers', 'slug' => 'seller-trousers',
            'product_type' => 'simple', 'status' => 'active',
        ]);
        $houseQuestion = ProductQuestion::create(['product_id' => $houseProduct->id, 'customer_id' => $customer->id, 'question' => 'House question?']);
        $sellerQuestion = ProductQuestion::create(['product_id' => $sellerProduct->id, 'customer_id' => $customer->id, 'question' => 'Seller question?']);
        $admin = Admin::create(['name' => 'Admin', 'email' => 'question-admin@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);

        $adminToken = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $this->withToken($adminToken)->getJson('/api/admin/product-questions')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $houseQuestion->id);
        $this->withToken($adminToken)->postJson("/api/admin/product-questions/{$houseQuestion->id}/answer", ['answer' => 'House answer'])
            ->assertOk();

        $sellerToken = $seller->createToken('test', ['seller:basic'])->plainTextToken;
        $this->withToken($sellerToken)->getJson('/api/seller/product-questions')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sellerQuestion->id);
        $this->withToken($sellerToken)->postJson("/api/seller/product-questions/{$houseQuestion->id}/answer", ['answer' => 'Forbidden answer'])
            ->assertForbidden();
        $this->withToken($sellerToken)->postJson("/api/seller/product-questions/{$sellerQuestion->id}/answer", ['answer' => 'Seller answer'])
            ->assertOk();
    }

    public function test_question_moderation_persists_and_publicly_hides_rejected_content(): void
    {
        [$customer, $product] = $this->fixtures();
        $question = ProductQuestion::create(['product_id' => $product->id, 'customer_id' => $customer->id, 'question' => 'Is this washable?']);
        $admin = Admin::create(['name' => 'Moderator', 'email' => 'question-moderator@example.test', 'password' => 'password123', 'role' => 'admin']);
        $this->withToken($admin->createToken('test', ['admin:basic'])->plainTextToken);
        $this->postJson("/api/admin/product-questions/{$question->id}/moderate", ['status' => 'rejected', 'rejection_note' => 'Private phone number'])->assertOk()->assertJsonPath('question.rejection_note', 'Private phone number');
        $this->getJson('/api/admin/product-questions?state=rejected')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/store/products/{$product->slug}/questions")->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/admin/product-questions/{$question->id}/moderate", ['status' => 'approved'])->assertOk()->assertJsonPath('question.rejection_note', null);
        $this->postJson("/api/admin/product-questions/{$question->id}", ['question' => 'Is this machine washable?', 'answer' => 'Yes, use cold water.'])->assertOk()->assertJsonPath('question.answered_by', 'Moderator');
        $answeredAt = $question->fresh()->answered_at->toISOString();
        $this->travel(1)->day();
        $this->postJson("/api/admin/product-questions/{$question->id}", ['question' => 'Is this garment machine washable?', 'answer' => 'Yes, use cold water.'])->assertOk();
        $this->assertSame($answeredAt, $question->fresh()->answered_at->toISOString());
        $this->getJson("/api/store/products/{$product->slug}/questions")->assertOk()->assertJsonPath('data.0.answer', 'Yes, use cold water.');
        $this->postJson("/api/admin/product-questions/{$question->id}", ['question' => 'Is this washable?', 'answer' => ''])->assertOk()->assertJsonPath('question.answered_at', null);
        $this->postJson("/api/admin/product-questions/{$question->id}/delete")->assertOk();
        $this->getJson("/api/store/products/{$product->slug}/questions")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_question_edits_and_deletes_preserve_existing_owner_boundaries(): void
    {
        [$customer, $product] = $this->fixtures();
        $question = ProductQuestion::create(['product_id' => $product->id, 'customer_id' => $customer->id, 'question' => 'Is this washable?']);
        $seller = Seller::create(['seller_name' => 'Owner', 'email' => 'question-owner@example.test', 'store_name' => 'Owner store', 'store_slug' => 'owner-store', 'kyc_type' => 'nid', 'kyc_number' => 'test', 'kyc_document_url' => 'https://example.test/id', 'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123']);
        $this->withToken($seller->createToken('test', ['seller:basic'])->plainTextToken);
        $this->postJson("/api/seller/product-questions/{$question->id}", ['question' => 'Tampered question', 'answer' => 'Tampered answer'])->assertForbidden();
        $this->postJson("/api/seller/product-questions/{$question->id}/delete")->assertForbidden();
        $product->update(['seller_id' => $seller->id]);
        $this->postJson("/api/seller/product-questions/{$question->id}", ['question' => 'Is this washable?', 'answer' => 'Yes, cold wash.'])->assertOk();
        $this->postJson("/api/seller/product-questions/{$question->id}/delete")->assertOk();
    }

    public function test_review_media_requires_owned_assets_and_delivered_items(): void
    {
        [$customer, $product] = $this->fixtures();
        $other = Customer::create(['name'=>'Other','email'=>'other-media@example.test','password'=>'password123']);
        $owned = \App\Models\MediaAsset::create(['owner_type'=>'customer','owner_id'=>$customer->id,'source'=>'upload','url'=>'https://example.test/owned.jpg','file_name'=>'owned.jpg','mime_type'=>'image/jpeg','size_bytes'=>100]);
        $foreign = \App\Models\MediaAsset::create(['owner_type'=>'customer','owner_id'=>$other->id,'source'=>'upload','url'=>'https://example.test/foreign.jpg','file_name'=>'foreign.jpg','mime_type'=>'image/jpeg','size_bytes'=>100]);
        $order = Order::create(['order_number'=>'ORD-REVIEW-MEDIA','customer_id'=>$customer->id,'status'=>'delivered','payment_status'=>'paid','subtotal'=>100,'total'=>100,'shipping_name'=>'Customer','shipping_phone'=>'01700000000','shipping_address'=>'Dhaka']);
        $item = OrderItem::create(['order_id'=>$order->id,'product_id'=>$product->id,'product_name'=>$product->name,'product_slug'=>$product->slug,'quantity'=>1,'unit_selling_price'=>100,'unit_buying_price'=>50,'line_subtotal'=>100,'line_cost'=>50,'line_profit'=>50,'fulfillment_status'=>'delivered','variation_attributes'=>['Size'=>'M']]);
        $this->withToken($customer->createToken('test',['customer:basic'])->plainTextToken);
        $url = "/api/customers/products/{$product->slug}/reviews";
        $base = ['order_item_id'=>$item->id,'rating'=>4,'comment'=>'Fits well.','videos'=>[]];
        $this->postJson($url,$base+['images'=>[$foreign->url]])->assertUnprocessable();
        $this->postJson($url,$base+['images'=>['https://example.test/unowned.jpg']])->assertUnprocessable();
        $id=$this->postJson($url,$base+['images'=>[$owned->url]])->assertCreated()->assertJsonPath('review.images.0',$owned->url)->json('review.id');
        $this->getJson('/api/customers/reviews?product_id='.$product->id)->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.media_details.0.file_name','owned.jpg');
        $this->getJson('/api/customers/reviewable-items?product_id='.$product->id)->assertOk()->assertJsonCount(0,'data');
        $this->getJson("/api/store/products/{$product->slug}/reviews")->assertOk()->assertJsonCount(0,'data');
        $admin=Admin::create(['name'=>'Moderator','email'=>'media-moderator@example.test','password'=>'password123','role'=>'admin']);
        $this->withToken($admin->createToken('test',['admin:basic'])->plainTextToken);
        $this->postJson("/api/admin/product-reviews/{$id}/moderate",['status'=>'approved'])->assertOk();
        $this->getJson("/api/store/products/{$product->slug}/reviews")->assertOk()->assertJsonPath('data.0.images.0',$owned->url)->assertJsonPath('data.0.purchased_variant.Size','M')->assertJsonPath('data.0.verified',true);
        $this->postJson("/api/admin/product-reviews/{$id}",['comment'=>'Edited','images'=>[$owned->url,$foreign->url],'videos'=>[]])->assertUnprocessable();
        $this->postJson("/api/admin/product-reviews/{$id}",['rating'=>1,'images'=>[],'videos'=>[]])->assertUnprocessable();
        $this->postJson("/api/admin/product-reviews/{$id}",['comment'=>'Edited','images'=>[],'videos'=>[],'seller_response'=>'Thank you.'])->assertOk()->assertJsonPath('review.images',[]);
        $this->assertDatabaseHas('reviews',['id'=>$id,'rating'=>4,'comment'=>'Edited']);
    }

    public function test_helpful_votes_are_private_idempotent_and_target_specific(): void
    {
        [$customer,$product]=$this->fixtures();
        $review=Review::create(['product_id'=>$product->id,'customer_id'=>$customer->id,'rating'=>5,'comment'=>'Good','status'=>'approved','seller_response'=>'Thank you.']);
        $other=Customer::create(['name'=>'Other','email'=>'other-voter@example.test','password'=>'password123']);
        $this->withToken($customer->createToken('test',['customer:basic'])->plainTextToken);
        $url="/api/customers/reviews/{$review->id}/like";
        $this->postJson($url,['liked'=>true])->assertOk()->assertJsonPath('likes',1);
        $this->postJson($url,['liked'=>true])->assertOk()->assertJsonPath('likes',1);
        $this->postJson($url,['liked'=>true,'target_type'=>'seller_response'])->assertOk()->assertJsonPath('likes',1);
        $this->getJson('/api/customers/review-likes')->assertOk()->assertExactJson([$review->id]);
        $this->getJson('/api/customers/review-likes?target_type=seller_response')->assertOk()->assertExactJson([$review->id]);
        $this->withToken($other->createToken('test',['customer:basic'])->plainTextToken)->getJson('/api/customers/review-likes')->assertOk()->assertExactJson([]);
        $this->postJson($url,['liked'=>true])->assertOk()->assertJsonPath('likes',2);
        $this->withToken($customer->createToken('test2',['customer:basic'])->plainTextToken)->postJson($url,['liked'=>false])->assertOk()->assertJsonPath('likes',1);
        $this->getJson("/api/store/products/{$product->slug}/reviews")->assertOk()->assertJsonPath('data.0.likes',1)->assertJsonPath('data.0.seller_response_likes',1);
        $review->update(['status'=>'rejected']);
        $this->postJson($url,['liked'=>true])->assertNotFound();
    }

    public function test_customer_review_drafts_are_hidden_from_admin_media_library(): void
    {
        [$customer]=$this->fixtures();
        $asset=\App\Models\MediaAsset::create(['owner_type'=>'customer','owner_id'=>$customer->id,'source'=>'upload','url'=>'https://example.test/private.jpg','file_name'=>'private.jpg','mime_type'=>'image/jpeg']);
        $admin=Admin::create(['name'=>'Admin','email'=>'private-media-admin@example.test','password'=>'password123','role'=>'super_admin']);
        $this->withToken($admin->createToken('test',['admin:basic'])->plainTextToken);
        $this->getJson('/api/admin/media')->assertOk()->assertJsonCount(0,'data');
        $this->postJson("/api/admin/media/{$asset->id}",['alt_text'=>'Tampered'])->assertNotFound();
        $this->postJson("/api/admin/media/{$asset->id}/delete")->assertNotFound();
        $this->assertDatabaseHas('media_assets',['id'=>$asset->id,'owner_type'=>'customer']);
    }

    public function test_seller_replies_require_an_owned_published_review(): void
    {
        [$customer,$product]=$this->fixtures();
        $review=Review::create(['product_id'=>$product->id,'customer_id'=>$customer->id,'rating'=>4,'comment'=>'Fits well','status'=>'pending']);
        $seller=Seller::create(['seller_name'=>'Owner','email'=>'reply-owner@example.test','store_name'=>'Owner Store','store_slug'=>'reply-owner-store','kyc_type'=>'nid','kyc_number'=>'reply-test','kyc_document_url'=>'https://example.test/id','product_category'=>'Clothing','status'=>'approved','is_active'=>true,'password'=>'password123']);
        $this->withToken($seller->createToken('test',['seller:basic'])->plainTextToken);
        $url="/api/seller/product-reviews/{$review->id}/respond";
        $this->postJson($url,['seller_response'=>'Thanks'])->assertForbidden();
        $product->update(['seller_id'=>$seller->id]);
        $this->postJson($url,['seller_response'=>'Thanks'])->assertUnprocessable();
        $review->update(['status'=>'approved']);
        $this->postJson($url,['seller_response'=>' Thanks for the feedback. '])->assertOk()->assertJsonPath('review.seller_response','Thanks for the feedback.');
        $this->assertNotNull($review->fresh()->seller_responded_at);
        $this->getJson("/api/store/products/{$product->slug}/reviews")->assertOk()->assertJsonPath('data.0.seller_response','Thanks for the feedback.');
        $this->postJson($url,['seller_response'=>''])->assertOk()->assertJsonPath('review.seller_response',null)->assertJsonPath('review.seller_responded_at',null);
    }

    private function fixtures(): array
    {
        $customer = Customer::create(['name' => 'Customer', 'email' => 'customer-engagement@example.test', 'password' => 'password123', 'email_verified_at' => now()]);
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Cotton Shirt', 'slug' => 'cotton-shirt', 'description' => 'A cotton shirt.', 'specifications' => [['label' => 'Material', 'value' => 'Cotton']], 'product_type' => 'simple', 'status' => 'active']);
        return [$customer, $product];
    }
}
