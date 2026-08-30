<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            [
                'name' => 'Cash on Delivery',
                'code' => 'cod',
                'description' => 'Pay with cash when the order is delivered.',
                'sort_order' => 1,
            ],
            [
                'name' => 'Card',
                'code' => 'card',
                'description' => 'Pay using a debit or credit card.',
                'sort_order' => 2,
            ],
            [
                'name' => 'Payment Gateway',
                'code' => 'payment_gateway',
                'description' => 'Pay through an online payment gateway.',
                'sort_order' => 3,
            ],
        ];

        foreach ($methods as $method) {
            PaymentMethod::updateOrCreate(
                ['code' => $method['code']],
                array_merge($method, ['is_active' => true])
            );
        }
    }
}
