<?php
namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Seller;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SizeChartMeasurementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_measurement_charts_persist_and_reach_storefront_with_scoped_edits(): void
    {
        $admin = Admin::create(['name'=>'Admin', 'email'=>'size-admin@example.test', 'password'=>'password123', 'role'=>'super_admin', 'is_active'=>true]);
        $basic = Admin::create(['name'=>'Basic', 'email'=>'size-basic@example.test', 'password'=>'password123', 'role'=>'admin', 'is_active'=>true]);
        $seller = Seller::create(['seller_name'=>'Seller', 'email'=>'size-seller@example.test', 'store_name'=>'Size Store', 'store_slug'=>'size-store', 'kyc_type'=>'nid', 'kyc_number'=>'123', 'kyc_document_url'=>'https://example.test/id', 'product_category'=>'Clothing', 'status'=>'approved', 'is_active'=>true, 'password'=>'password123']);
        $adminToken=$admin->createToken('test',['admin:basic'])->plainTextToken;
        $basicToken=$basic->createToken('test',['admin:basic'])->plainTextToken;
        $sellerToken=$seller->createToken('test',['seller:basic'])->plainTextToken;
        $category=ProductCategory::create(['name'=>'Panjabi','slug'=>'size-panjabi']);
        $input=['name'=>'Panjabi — Slim', 'unit'=>'cm', 'audience'=>'men', 'category_slug'=>$category->slug, 'columns'=>[['id'=>'chest','label'=>'Chest']], 'rows'=>[['id'=>'m','label'=>'M','values'=>['chest'=>'38–40']],['id'=>'l','label'=>'L','values'=>['chest'=>'']]], 'note'=>'Allow 1 cm tolerance.'];
        $id=$this->withToken($sellerToken)->postJson('/api/seller/size-charts',$input)->assertCreated()->assertJsonPath('size_chart.rows.1.values.chest','')->json('size_chart.id');
        $this->withToken($adminToken)->getJson('/api/admin/size-charts')->assertOk()->assertJsonPath('0.id',$id)->assertJsonPath('0.products_count',0);
        $this->withToken($basicToken)->getJson('/api/admin/size-charts')->assertOk()->assertJsonCount(0);
        $this->withToken($basicToken)->postJson("/api/admin/size-charts/{$id}",['name'=>'Forbidden'])->assertForbidden();
        $this->withToken($adminToken)->postJson("/api/admin/size-charts/{$id}",['note'=>'Updated note'])->assertOk();
        $product=Product::create(['category_id'=>$category->id,'seller_id'=>$seller->id,'size_chart_id'=>$id,'name'=>'Slim Panjabi','slug'=>'slim-panjabi','product_type'=>'simple','status'=>'active']);
        $this->getJson('/api/store/products/slim-panjabi')->assertOk()->assertJsonPath('size_chart.unit','cm')->assertJsonPath('size_chart.rows.0.values.chest','38–40')->assertJsonPath('size_chart.note','Updated note');
        $this->withToken($sellerToken)->postJson("/api/seller/size-charts/{$id}/delete")->assertUnprocessable();
        $this->withToken($sellerToken)->postJson('/api/seller/size-charts',[...$input,'rows'=>[['id'=>'m','label'=>'M','values'=>[]],['id'=>'m','label'=>'Duplicate','values'=>[]]]])->assertUnprocessable();
        $global=$this->withToken($adminToken)->postJson('/api/admin/size-charts',['name'=>'Legacy guide','url'=>'https://example.test/guide.png'])->assertCreated()->json('size_chart.id');
        $this->withToken($sellerToken)->postJson("/api/seller/size-charts/{$global}",['name'=>'Forbidden'])->assertForbidden();
        $this->withToken($sellerToken)->getJson('/api/seller/size-charts')->assertOk()->assertJsonCount(2);
    }
}
