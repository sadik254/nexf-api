<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Admin;
use App\Models\Product;
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

    private function fixtures(): array
    {
        $customer = Customer::create(['name' => 'Customer', 'email' => 'customer-engagement@example.test', 'password' => 'password123', 'email_verified_at' => now()]);
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Cotton Shirt', 'slug' => 'cotton-shirt', 'description' => 'A cotton shirt.', 'specifications' => [['label' => 'Material', 'value' => 'Cotton']], 'product_type' => 'simple', 'status' => 'active']);
        return [$customer, $product];
    }
}
