<?php

namespace Database\Seeders;

use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

class ProductCategorySeeder extends Seeder
{
    public function run(): void
    {
        $tree = [
            'men' => ['Men', ['t-shirts-polos'=>'T-Shirts & Polos','shirts'=>'Shirts','panjabi'=>'Panjabi','trousers-jeans'=>'Trousers & Jeans','watches'=>'Watches','footwear'=>'Footwear']],
            'women' => ['Women', ['salwar-kameez'=>'Salwar Kameez','sarees'=>'Sarees','kurtis-tunics'=>'Kurtis & Tunics','tops-blouses'=>'Tops & Blouses','handbags'=>'Handbags','jewellery'=>'Jewellery']],
            'kids' => ['Kids', ['boys-wear'=>"Boys' Wear",'girls-wear'=>"Girls' Wear",'party-wear'=>'Party Wear']],
            'electronics' => ['Electronics', ['headphones'=>'Headphones','earbuds'=>'Earbuds','smartwatches'=>'Smartwatches','laptops'=>'Laptops','phones'=>'Phones']],
            'home-living' => ['Home & Living', ['bedding'=>'Bedding','kitchen'=>'Kitchen','decor'=>'Decor']],
        ];
        foreach ($tree as $slug => [$name, $children]) {
            $parent = ProductCategory::updateOrCreate(['slug' => $slug], ['name' => $name, 'parent_id' => null, 'is_active' => true]);
            foreach ($children as $childSlug => $childName) {
                ProductCategory::updateOrCreate(['slug' => $childSlug], ['name' => $childName, 'parent_id' => $parent->id, 'is_active' => true]);
            }
        }
    }
}
