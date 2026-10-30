<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_all_current_checkout_payment_methods_in_display_order(): void
    {
        $this->seed(PaymentMethodSeeder::class);

        $this->assertSame(
            ['cod', 'card', 'payment_gateway', 'wallet', 'bkash', 'nagad', 'rocket', 'upay', 'bank'],
            PaymentMethod::orderBy('sort_order')->pluck('code')->all(),
        );
        $this->assertDatabaseHas('payment_methods', [
            'code' => 'upay',
            'name' => 'Upay',
            'description' => 'Send money to our Upay number.',
            'is_active' => true,
            'sort_order' => 8,
        ]);
    }
}
