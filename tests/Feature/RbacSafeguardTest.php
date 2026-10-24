<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Seller;
use App\Models\SellerStaff;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacSafeguardTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_cannot_delete_or_edit_the_owner_profile(): void
    {
        $seller = Seller::create([
            'seller_name' => 'Owner', 'email' => 'owner@example.test', 'store_name' => 'Owner Store',
            'store_slug' => 'owner-store', 'status' => 'approved', 'is_active' => true, 'password' => 'password123',
        ]);
        $staff = SellerStaff::create([
            'seller_id' => $seller->id, 'name' => 'Staff', 'email' => 'staff@example.test',
            'password' => 'password123', 'status' => 'active',
        ]);
        $token = $staff->createToken('test', ['seller'])->plainTextToken;
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']);
        $product = Product::create([
            'seller_id' => $seller->id, 'category_id' => $category->id, 'name' => 'Item',
            'slug' => 'staff-item', 'product_type' => 'simple', 'status' => 'active',
        ]);

        $this->withToken($token)->postJson("/api/seller/products/{$product->id}/delete")->assertForbidden()
            ->assertJsonPath('permission', 'products.delete');
        $this->withToken($token)->postJson('/api/sellers/me', ['seller_name' => 'Changed'])->assertForbidden();
    }

    public function test_the_final_active_super_admin_cannot_be_removed(): void
    {
        $actor = Admin::create(['name' => 'Only Super', 'email' => 'only@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $token = $actor->createToken('test', ['admin'])->plainTextToken;

        $this->withToken($token)->postJson("/api/admin/admins/{$actor->id}/delete")
            ->assertUnprocessable()->assertJsonPath('message', 'You cannot delete yourself.');
    }
}
