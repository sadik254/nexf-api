<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\FraudGuardRule;
use App\Models\FraudGuardBlock;
use App\Models\FraudGuardSetting;
use App\Models\Order;
use Illuminate\Validation\ValidationException;

class FraudGuardService
{
    public function assertCodAllowed(?string $phone, float $total): void
    {
        foreach (FraudGuardRule::query()->where('is_active', true)->where('rule_type', 'cod_block')->get() as $rule) {
            $config = $rule->configuration ?? [];
            if (isset($config['threshold']) && $total >= (float) $config['threshold']) {
                throw ValidationException::withMessages(['payment' => ['Orders to this number need to be paid before delivery.']]);
            }
        }
    }
    public function assertAllowed(?Customer $customer, array $checkout, float $total): void
    {
        // Do not turn on a new checkout restriction merely by deploying its
        // table. The console creates the explicit settings record on first
        // save, after an administrator has reviewed the defaults.
        $settings = FraudGuardSetting::first();
        if ($settings?->enabled) {
            $phone = preg_replace('/\D/', '', (string) ($checkout['shipping_phone'] ?? ''));
            if (str_starts_with($phone, '880')) $phone = substr($phone, 2);
            if ($settings->fake_number_detection && (!preg_match('/^01[3-9]\d{8}$/', $phone) || preg_match('/^01\d(\d)\1{7}$/', $phone))) throw ValidationException::withMessages(['shipping_phone' => ['Enter a valid Bangladeshi mobile number.']]);
            $blocked = FraudGuardBlock::query();
            if (empty($checkout['staff_order']) && (($settings->phone_blacklist && (clone $blocked)->where('kind', 'phone')->where('value', $phone)->exists()) || ($settings->ip_block && !empty($checkout['ip_address']) && (clone $blocked)->where('kind', 'ip')->where('value', strtolower($checkout['ip_address']))->exists()) || ($settings->device_block && !empty($checkout['device_id']) && (clone $blocked)->where('kind', 'device')->where('value', strtolower($checkout['device_id']))->exists()))) throw ValidationException::withMessages(['checkout' => ["We couldn't place this order. Please contact support if you think this is a mistake."]]);
            if (empty($checkout['staff_order']) && $settings->repeat_order_block) {
                $this->assertRepeatOrderAllowed($customer, $checkout, max(1, (int) $settings->repeat_order_minutes));
            }
        }
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

    private function assertRepeatOrderAllowed(?Customer $customer, array $checkout, int $baseMinutes): void
    {
        $phone = $this->normalisePhone($checkout['shipping_phone'] ?? null);
        $ip = trim((string) ($checkout['ip_address'] ?? ''));
        $device = trim((string) ($checkout['device_id'] ?? ''));
        if (! $customer && $phone === '' && $ip === '' && $device === '') return;

        $orders = Order::query()->where('status', '!=', 'cancelled')->where('staff_order', false)
            ->where(function ($query) use ($customer, $phone, $ip, $device) {
                if ($customer) $query->orWhere('customer_id', $customer->id);
                if ($phone !== '') $query->orWhere('shipping_phone', 'like', '%'.substr($phone, -10));
                if ($ip !== '') $query->orWhere('ip_address', $ip);
                if ($device !== '') $query->orWhere('device_id', $device);
            })->latest('created_at')->get()
            ->filter(fn (Order $order) => ($phone === '' || $this->normalisePhone($order->shipping_phone) === $phone) || ($customer && $order->customer_id === $customer->id) || ($ip !== '' && $order->ip_address === $ip) || ($device !== '' && $order->device_id === $device))
            ->values();
        if ($orders->isEmpty()) return;

        $run = 1;
        for ($index = 1; $index < $orders->count(); $index++) {
            if ($orders[$index - 1]->created_at->diffInHours($orders[$index]->created_at) >= 24) break;
            $run++;
        }
        $wait = $baseMinutes * (2 ** ($run - 1));
        $availableAt = $orders->first()->created_at->copy()->addMinutes($wait);
        if ($availableAt->isFuture()) {
            $left = max(1, (int) ceil(now()->diffInSeconds($availableAt, false) / 60));
            throw ValidationException::withMessages(['checkout' => ["You've just placed an order. You can order again in {$left} minutes."]]);
        }
    }

    private function normalisePhone(?string $phone): string
    {
        $phone = preg_replace('/\D/', '', (string) $phone);
        if (str_starts_with($phone, '880')) $phone = substr($phone, 2);
        return $phone;
    }
}
