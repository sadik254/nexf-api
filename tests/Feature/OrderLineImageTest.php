<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLot;
use App\Models\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderLineImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_freezes_only_a_photo_owned_by_the_purchased_product(): void
    {
        $customer = Customer::create(['name' => 'Photo buyer', 'email' => 'photo-buyer@example.test', 'password' => 'password123']);
        $admin = Admin::create(['name' => 'Photo admin', 'email' => 'photo-admin@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $category = ProductCategory::create(['name' => 'Jackets', 'slug' => 'line-image-jackets']);
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Placement jacket', 'slug' => 'placement-jacket',
            'product_type' => 'simple', 'status' => 'active', 'default_selling_price' => 100,
            'thumbnail' => 'https://media.example.test/jacket-men.jpg',
            'gallery' => ['https://media.example.test/jacket-detail.jpg', 'https://media.example.test/jacket-women.jpg'],
        ]);
        $other = Product::create(['category_id' => $category->id, 'name' => 'Other jacket', 'slug' => 'other-jacket', 'product_type' => 'simple', 'status' => 'active', 'thumbnail' => 'https://media.example.test/not-this-product.jpg']);
        ProductLot::create(['product_id' => $product->id, 'lot_number' => 'LINE-IMAGE', 'buying_price' => 40, 'selling_price' => 100, 'quantity' => 2, 'quantity_remaining' => 2]);
        $payment = PaymentMethod::create(['name' => 'Cash on Delivery', 'code' => 'cod', 'is_active' => true]);
        $shipping = ShippingMethod::create(['name' => 'Outside Dhaka', 'code' => 'outside_dhaka', 'charge' => 20, 'currency' => 'BDT', 'is_active' => true]);
        $token = $customer->createToken('test', ['customer:basic'])->plainTextToken;

        $order = $this->withToken($token)->postJson('/api/customers/orders', [
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'image_url' => 'https://media.example.test/jacket-women.jpg',
            ]],
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'shipping_name' => 'Photo buyer',
            'shipping_phone' => '01712345678',
            'shipping_address' => 'Dhaka',
        ])->assertCreated()
            ->assertJsonPath('order.items.0.line_image', 'https://media.example.test/jacket-women.jpg')
            ->json('order');

        $this->assertDatabaseHas('order_items', ['order_id' => $order['id'], 'line_image' => 'https://media.example.test/jacket-women.jpg']);
        $this->withToken($token)->getJson("/api/customers/orders/{$order['id']}")
            ->assertOk()->assertJsonPath('items.0.line_image', 'https://media.example.test/jacket-women.jpg');
        $this->withToken($admin->createToken('test', ['admin:basic'])->plainTextToken)->getJson("/api/admin/orders/{$order['id']}")
            ->assertOk()->assertJsonPath('items.0.line_image', 'https://media.example.test/jacket-women.jpg');

        ProductLot::create(['product_id' => $product->id, 'lot_number' => 'LINE-IMAGE-2', 'buying_price' => 40, 'selling_price' => 100, 'quantity' => 1, 'quantity_remaining' => 1]);
        $foreign = $this->withToken($token)->postJson('/api/customers/orders', [
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'image_url' => $other->thumbnail,
            ]],
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'shipping_name' => 'Photo buyer',
            'shipping_phone' => '01712345679',
            'shipping_address' => 'Dhaka',
        ])->assertCreated()->json('order');

        $this->assertNull($foreign['items'][0]['line_image']);
    }
}
