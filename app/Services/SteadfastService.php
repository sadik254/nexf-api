<?php

namespace App\Services;

use App\Models\OrderItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class SteadfastService
{
    public function createConsignment(OrderItem $item): array
    {
        $apiKey = (string) config('services.steadfast.api_key');
        $secretKey = (string) config('services.steadfast.secret_key');
        if ($apiKey === '' || $secretKey === '') {
            throw ValidationException::withMessages(['courier' => ['SteadFast credentials are not configured.']]);
        }

        $order = $item->order()->with('customer')->firstOrFail();
        $phone = preg_replace('/\D+/', '', (string) $order->shipping_phone);
        if (strlen($phone) !== 11) {
            throw ValidationException::withMessages(['shipping_phone' => ['A valid 11-digit phone number is required for SteadFast delivery.']]);
        }

        $invoice = $order->order_number . '-ITEM-' . $item->id;
        $payload = [
            'invoice' => $invoice,
            'recipient_name' => $order->shipping_name,
            'recipient_phone' => $phone,
            'recipient_email' => $order->customer?->email,
            'recipient_address' => $order->shipping_address,
            'cod_amount' => (float) $item->line_subtotal,
            'note' => $order->notes,
            'item_description' => $item->product_name,
            'total_lot' => $item->quantity,
            'delivery_type' => 0,
        ];

        $response = Http::timeout(config('services.steadfast.timeout', 15))
            ->acceptJson()
            ->withHeaders(['Api-Key' => $apiKey, 'Secret-Key' => $secretKey])
            ->post(rtrim(config('services.steadfast.base_url'), '/') . '/create_order', $payload);

        if (!$response->successful()) {
            throw ValidationException::withMessages(['courier' => ['SteadFast rejected the consignment request.']]);
        }

        $json = $response->json();
        $consignment = $json['consignment'] ?? null;
        if (($json['status'] ?? null) !== 200 || !is_array($consignment) || empty($consignment['tracking_code'])) {
            throw ValidationException::withMessages(['courier' => [$json['message'] ?? 'SteadFast returned an invalid response.']]);
        }

        return [
            'provider' => 'steadfast',
            'consignment_id' => (string) ($consignment['consignment_id'] ?? ''),
            'invoice' => (string) ($consignment['invoice'] ?? $invoice),
            'tracking_code' => (string) $consignment['tracking_code'],
            'status' => (string) ($consignment['status'] ?? 'in_review'),
            'created_at' => $consignment['created_at'] ?? null,
            'updated_at' => $consignment['updated_at'] ?? null,
        ];
    }
}
