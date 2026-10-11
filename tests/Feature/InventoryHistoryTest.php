<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_status_counts_select_only_grouped_columns(): void
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => 'products-admin@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $sql = strtolower(str_replace('"', '`', $query->sql));
            if (str_contains($sql, 'group by `products`.`status`')) {
                $queries[] = $sql;
            }
        });

        $this->withToken($admin->createToken('test', ['admin:basic'])->plainTextToken)
            ->getJson('/api/admin/products')->assertOk();

        $this->assertNotEmpty($queries);
        $this->assertStringNotContainsString('products`.*', $queries[0]);
        $this->assertStringNotContainsString('products`.`id`', $queries[0]);
    }

    public function test_receipts_are_scoped_to_the_owner_and_summary_covers_all_pages(): void
    {
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']);
        $admin = Admin::create(['name' => 'Admin', 'email' => 'inventory-admin@example.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $seller = Seller::create([
            'seller_name' => 'Seller', 'email' => 'inventory-seller@example.test', 'store_name' => 'Inventory Store',
            'store_slug' => 'inventory-store', 'kyc_type' => 'nid', 'kyc_number' => '123',
            'kyc_document_url' => 'https://example.test/kyc', 'product_category' => 'Clothing',
            'status' => 'approved', 'is_active' => true, 'password' => 'password123',
        ]);
        $house = Product::create(['category_id' => $category->id, 'name' => 'House', 'slug' => 'house', 'sku' => 'HOUSE-001', 'product_type' => 'simple', 'status' => 'active']);
        $second = Product::create(['category_id' => $category->id, 'name' => 'Second', 'slug' => 'second', 'product_type' => 'simple', 'status' => 'active']);
        $sellerProduct = Product::create(['seller_id' => $seller->id, 'category_id' => $category->id, 'name' => 'Seller', 'slug' => 'seller', 'product_type' => 'simple', 'status' => 'active']);

        $adminToken = $admin->createToken('test', ['admin:basic'])->plainTextToken;
        $sellerToken = $seller->createToken('test', ['seller:basic'])->plainTextToken;
        foreach ([[$house, 5, $adminToken, 'admin'], [$second, 2, $adminToken, 'admin'], [$sellerProduct, 9, $sellerToken, 'seller']] as [$product, $quantity, $token, $prefix]) {
            $this->withToken($token)->postJson("/api/{$prefix}/products/{$product->id}/lots", [
                'lot_number' => "LOT-{$product->id}", 'buying_price' => 10, 'selling_price' => 20, 'quantity' => $quantity,
            ])->assertCreated();
        }

        $this->withToken($adminToken)->getJson('/api/admin/inventory?per_page=1')->assertOk()
            ->assertJsonPath('total', 3)->assertJsonPath('summary.available_units', 16)
            ->assertJsonPath('summary.units_on_hand', 16)->assertJsonPath('summary.committed_units', 0)
            ->assertJsonPath('summary.stock_value_at_cost', 160)->assertJsonPath('summary.low_stock', 2)
            ->assertJsonPath('stock_counts.low', 2)->assertJsonPath('stock_counts.ok', 1);
        $this->withToken($adminToken)->getJson('/api/admin/inventory?stock=low&search=Second')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.product_name', 'Second');
        $this->withToken($adminToken)->getJson('/api/admin/inventory?stock=untracked')->assertOk()
            ->assertJsonPath('total', 0);
        $this->withToken($adminToken)->getJson('/api/admin/inventory?seller_id=house')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('data.0.sku', 'HOUSE-001');
        $this->withToken($adminToken)->getJson('/api/admin/products?per_page=1')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('status_counts.active', 2);
        $this->withToken($adminToken)->getJson('/api/admin/products?search=House')->assertOk()
            ->assertJsonPath('data.0.available_quantity', 5);
        $this->withToken($adminToken)->getJson('/api/admin/products?status=draft')->assertOk()
            ->assertJsonPath('total', 0)->assertJsonPath('status_counts.active', 2);
        $this->withToken($adminToken)->getJson('/api/admin/inventory/history')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('data.0.reason', 'received');
        $this->withToken($adminToken)->getJson('/api/admin/inventory/history?scope=all&product_id=' . $sellerProduct->id)->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.lot.lot_number', "LOT-{$sellerProduct->id}");
        $this->withToken($sellerToken)->getJson('/api/seller/inventory/history')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.lot.lot_number', "LOT-{$sellerProduct->id}");
        $this->withToken($sellerToken)->getJson('/api/seller/inventory?per_page=1')->assertOk()
            ->assertJsonPath('summary.available_units', 9);

        $houseLotId = $this->withToken($adminToken)->getJson('/api/admin/inventory')->json('data.0.lots.0.id');
        $this->withToken($sellerToken)->postJson("/api/seller/inventory/lots/{$houseLotId}/adjust", [
            'quantity_change' => -1, 'reason' => 'damaged',
        ])->assertForbidden();
        $this->withToken($adminToken)->postJson("/api/admin/inventory/lots/{$houseLotId}/adjust", [
            'quantity_change' => -100, 'reason' => 'damaged',
        ])->assertUnprocessable();
        $this->withToken($adminToken)->postJson("/api/admin/inventory/lots/{$houseLotId}/adjust", [
            'quantity_change' => -1, 'reason' => 'damaged', 'note' => 'Found during count',
        ])->assertOk();
        $this->withToken($adminToken)->postJson('/api/admin/inventory/counts', [
            'items' => [['product_id' => $house->id, 'available_quantity' => 7]], 'reason' => 'count_correction',
        ])->assertOk()->assertJsonPath('items.0.available_quantity', 7);
        $this->withToken($sellerToken)->postJson('/api/seller/inventory/counts', [
            'items' => [['product_id' => $sellerProduct->id, 'available_quantity' => 8]], 'reason' => 'count_correction',
        ])->assertOk()->assertJsonPath('items.0.available_quantity', 8);
        $this->withToken($sellerToken)->postJson('/api/seller/inventory/counts', [
            'items' => [['product_id' => $house->id, 'available_quantity' => 1]], 'reason' => 'count_correction',
        ])->assertForbidden();
        $untracked = Product::create(['category_id' => $category->id, 'name' => 'Untracked', 'slug' => 'untracked', 'product_type' => 'simple', 'status' => 'active']);
        $this->withToken($adminToken)->postJson('/api/admin/inventory/counts', [
            'items' => [['product_id' => $untracked->id, 'available_quantity' => 0]], 'reason' => 'count_correction',
        ])->assertOk();
        $this->withToken($adminToken)->getJson('/api/admin/inventory?stock=untracked')->assertOk()
            ->assertJsonPath('total', 0);
        $this->withToken($adminToken)->getJson('/api/admin/inventory?search=Untracked')->assertOk()
            ->assertJsonPath('data.0.lots.0.quantity', 0);
        $this->withToken($adminToken)->getJson('/api/admin/inventory/history?scope=all&reason=adjustment')->assertOk()
            ->assertJsonPath('total', 3);
    }
}
