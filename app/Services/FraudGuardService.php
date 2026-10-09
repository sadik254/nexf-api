<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\FraudGuardRule;
use App\Models\Order;
use Illuminate\Validation\ValidationException;

class FraudGuardService
{
    public function assertAllowed(Customer $customer, array $checkout, float $total): void
    {
        foreach (FraudGuardRule::query()->where('is_active', true)->get() as $rule) {
            $config = $rule->configuration ?? [];
            if ($rule->rule_type === 'order_value' && isset($config['threshold']) && $total > (float) $config['threshold']) {
                throw ValidationException::withMessages(['checkout' => ["{$rule->name}: order value requires manual review."]]);
            }
            if ($rule->rule_type === 'phone_velocity' && isset($config['threshold'])) {
                $window = max(1, (int) ($config['window_minutes'] ?? 60));
                $count = Order::query()->where('shipping_phone', $checkout['shipping_phone'] ?? '')->where('created_at', '>=', now()->subMinutes($window))->count();
                if ($count >= (int) $config['threshold']) throw ValidationException::withMessages(['shipping_phone' => ["{$rule->name}: too many recent orders for this phone number."]]);
            }
            if ($rule->rule_type === 'ip_velocity' && isset($config['threshold']) && !empty($checkout['ip_address'])) {
                $window = max(1, (int) ($config['window_minutes'] ?? 60));
                $count = Order::query()->where('ip_address', $checkout['ip_address'])->where('created_at', '>=', now()->subMinutes($window))->count();
                if ($count >= (int) $config['threshold']) throw ValidationException::withMessages(['checkout' => ["{$rule->name}: too many recent orders from this connection."]]);
            }
            if ($rule->rule_type === 'cod_block' && ($checkout['payment_method_code'] ?? null) === 'cod' && isset($config['threshold']) && $total >= (float) $config['threshold']) {
                throw ValidationException::withMessages(['payment_method_id' => ["{$rule->name}: cash on delivery is unavailable for this order value."]]);
            }
        }
    }
}
