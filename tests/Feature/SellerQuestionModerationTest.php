<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductQuestion;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerQuestionModerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_can_moderate_only_questions_for_own_products(): void
    {
        $customer = Customer::create(['name' => 'Buyer', 'email' => 'buyer@example.test', 'password' => 'password123']);
        $seller = $this->seller('owner@example.test', 'Owner Store', 'owner-store', 'one');
        $other = $this->seller('other@example.test', 'Other Store', 'other-store', 'two');
        $category = ProductCategory::create(['name' => 'Category', 'slug' => 'category']);
        $product = Product::create(['seller_id' => $seller->id, 'category_id' => $category->id, 'name' => 'Owned Product', 'slug' => 'owned-product', 'product_type' => 'simple', 'status' => 'active']);
        $otherProduct = Product::create(['seller_id' => $other->id, 'category_id' => $category->id, 'name' => 'Other Product', 'slug' => 'other-product', 'product_type' => 'simple', 'status' => 'active']);
        $question = ProductQuestion::create(['product_id' => $product->id, 'customer_id' => $customer->id, 'question' => 'Does this include a warranty?']);
        $foreign = ProductQuestion::create(['product_id' => $otherProduct->id, 'customer_id' => $customer->id, 'question' => 'Can this be delivered tomorrow?']);
        $token = $seller->createToken('test', ['seller:basic'])->plainTextToken;

        $this->withToken($token)->postJson("/api/seller/product-questions/{$question->id}/moderate", [
            'status' => 'rejected', 'rejection_note' => 'Please avoid personal contact details.',
        ])->assertOk()
            ->assertJsonPath('question.status', 'rejected')
            ->assertJsonPath('question.rejection_note', 'Please avoid personal contact details.');

        $this->withToken($token)->postJson("/api/seller/product-questions/{$question->id}/moderate", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('question.status', 'approved')
            ->assertJsonPath('question.rejection_note', null);
        $this->withToken($token)->postJson("/api/seller/product-questions/{$foreign->id}/moderate", ['status' => 'rejected'])
            ->assertNotFound();
    }

    private function seller(string $email, string $storeName, string $slug, string $suffix): Seller
    {
        return Seller::create([
            'seller_name' => $storeName, 'email' => $email, 'store_name' => $storeName, 'store_slug' => $slug,
            'kyc_type' => 'nid', 'kyc_number' => $suffix, 'kyc_document_url' => 'https://example.test/'.$suffix,
            'product_category' => 'Clothing', 'status' => 'approved', 'is_active' => true, 'password' => 'password123',
        ]);
    }
}
