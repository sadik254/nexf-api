<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
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
    }

    public function test_review_requires_a_delivered_purchase(): void
    {
        [$customer, $product] = $this->fixtures();
        $token = $customer->createToken('test', ['customer:basic'])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/customers/products/{$product->slug}/reviews", ['rating' => 5, 'comment' => 'Excellent.'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only customers with a delivered purchase can review this product.');
    }

    private function fixtures(): array
    {
        $customer = Customer::create(['name' => 'Customer', 'email' => 'customer-engagement@example.test', 'password' => 'password123', 'email_verified_at' => now()]);
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Cotton Shirt', 'slug' => 'cotton-shirt', 'description' => 'A cotton shirt.', 'specifications' => [['label' => 'Material', 'value' => 'Cotton']], 'product_type' => 'simple', 'status' => 'active']);
        return [$customer, $product];
    }
}
