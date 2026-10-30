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
            [
                'name' => 'NEXF Balance',
                'code' => 'wallet',
                'description' => 'Pay using your available NEXF balance.',
                'sort_order' => 4,
            ],
            [
                'name' => 'bKash',
                'code' => 'bkash',
                'description' => 'Send money to our bKash number.',
                'sort_order' => 5,
            ],
            [
                'name' => 'Nagad',
                'code' => 'nagad',
                'description' => 'Send money to our Nagad number.',
                'sort_order' => 6,
            ],
            [
                'name' => 'Rocket',
                'code' => 'rocket',
                'description' => 'Send money to our Rocket number.',
                'sort_order' => 7,
            ],
            [
                'name' => 'Upay',
                'code' => 'upay',
                'description' => 'Send money to our Upay number.',
                'sort_order' => 8,
            ],
            [
                'name' => 'Bank Transfer',
                'code' => 'bank',
                'description' => 'Transfer to our bank account.',
                'sort_order' => 9,
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
