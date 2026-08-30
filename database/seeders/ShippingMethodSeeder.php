<?php

namespace Database\Seeders;

use App\Models\ShippingMethod;
use Illuminate\Database\Seeder;

class ShippingMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            [
                'name' => 'Inside Dhaka',
                'code' => 'inside_dhaka',
                'description' => 'Flat delivery charge inside Dhaka.',
                'charge' => 80,
                'currency' => 'BDT',
                'sort_order' => 1,
            ],
            [
                'name' => 'Outside Dhaka',
                'code' => 'outside_dhaka',
                'description' => 'Flat delivery charge outside Dhaka.',
                'charge' => 120,
                'currency' => 'BDT',
                'sort_order' => 2,
            ],
        ];

        foreach ($methods as $method) {
            ShippingMethod::updateOrCreate(
                ['code' => $method['code']],
                array_merge($method, ['is_active' => true])
            );
        }
    }
}
