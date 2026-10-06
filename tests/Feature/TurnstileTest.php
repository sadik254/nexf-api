<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLot;
use App\Models\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TurnstileTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_needs_a_valid_turnstile_token_once_configured(): void
    {
        config(['services.turnstile.secret_key' => 'secret']);
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()
            ->push(['success' => false])
            ->push(['success' => true])]);

        $category = ProductCategory::create(['name' => 'Category', 'slug' => 'category']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Product', 'slug' => 'product', 'product_type' => 'simple', 'status' => 'active', 'default_selling_price' => 100]);
        ProductLot::create(['product_id' => $product->id, 'lot_number' => 'LOT-1', 'buying_price' => 50, 'selling_price' => 100, 'quantity' => 5, 'quantity_remaining' => 5]);
        $payment = PaymentMethod::create(['name' => 'COD', 'code' => 'cod', 'is_active' => true]);
        $shipping = ShippingMethod::create(['name' => 'Standard', 'code' => 'standard', 'charge' => 20, 'currency' => 'BDT', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Customer', 'email' => 'c@example.test', 'password' => 'password123', 'email_verified_at' => now()]);
        $token = $customer->createToken('test', ['customer:basic'])->plainTextToken;

        $order = fn (array $extra = []) => $this->withToken($token)->postJson('/api/customers/orders', $extra + [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'shipping_name' => 'Customer',
            'shipping_phone' => '01712345678',
            'shipping_address' => 'Dhaka',
        ]);

        $order()->assertUnprocessable()->assertJsonValidationErrors('turnstile_token');
        $order(['turnstile_token' => 'bad'])->assertUnprocessable()->assertJsonValidationErrors('turnstile_token');
        $order(['turnstile_token' => 'good'])->assertCreated();
    }
}
