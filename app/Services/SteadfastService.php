<?php

namespace App\Services;

use App\Models\OrderStoreGroup;
use App\Models\Seller;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Books NEXF's own parcels with Steadfast. One consignment per sub-order (store
 * group): the group number is the invoice, its lines are the description and
 * its COD is what the customer still owes for that parcel.
 */
class SteadfastService
{
    public function __construct(private SubOrderService $subOrders) {}

    /** @param iterable<OrderStoreGroup> $groups */
    public function createBulkConsignments(iterable $groups): array
    {
        [$apiKey, $secretKey] = $this->credentials();

        $payload = [];
        foreach ($groups as $group) {
            $parcel = $this->parcel($group);
            if (is_string($parcel)) {
                $group->update(['courier_error' => $parcel]);
                continue;
            }
            $payload[$parcel['invoice']] = $parcel;
        }

        if ($payload === []) return [];
        $response = Http::timeout(config('services.steadfast.timeout', 15))
            ->acceptJson()->withHeaders(['Api-Key' => $apiKey, 'Secret-Key' => $secretKey])
            ->post(rtrim(config('services.steadfast.base_url'), '/') . '/create_order/bulk-order', ['data' => json_encode(array_values($payload))]);

        // Steadfast response logging intentionally disabled after integration.
        // Log::info('Steadfast bulk consignment response', [
        //     'http_status' => $response->status(),
        //     'invoices' => array_keys($payload),
        //     'response' => $response->json() ?? $response->body(),
        // ]);

        if (!$response->successful()) throw ValidationException::withMessages(['courier' => ['SteadFast bulk request failed.']]);
        $results = $response->json('data', $response->json());
        return is_array($results) ? $results : [];
    }

    /**
     * Books one parcel. A store's parcel goes on the store's own Steadfast
     * account (its keys from Store settings); NEXF's goes on NEXF's.
     */
    public function createConsignment(OrderStoreGroup $group): array
    {
        [$apiKey, $secretKey] = $this->credentials($group->ships_by === 'seller' ? $group->seller : null);
        $payload = $this->parcel($group);
        if (is_string($payload)) throw ValidationException::withMessages(['shipping_phone' => [$payload]]);

        $response = Http::timeout(config('services.steadfast.timeout', 15))
            ->acceptJson()
            ->withHeaders(['Api-Key' => $apiKey, 'Secret-Key' => $secretKey])
            ->post(rtrim(config('services.steadfast.base_url'), '/') . '/create_order', $payload);

        // Steadfast response logging intentionally disabled after integration.
        // Log::info('Steadfast consignment response', [
        //     'store_group_id' => $group->id,
        //     'invoice' => $payload['invoice'],
        //     'http_status' => $response->status(),
        //     'response' => $response->json() ?? $response->body(),
        // ]);

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
            'invoice' => (string) ($consignment['invoice'] ?? $payload['invoice']),
            'tracking_code' => (string) $consignment['tracking_code'],
            'tracking_url' => isset($consignment['tracking_link']) ? (string) $consignment['tracking_link'] : null,
            'status' => (string) ($consignment['status'] ?? 'in_review'),
            'created_at' => $consignment['created_at'] ?? null,
            'updated_at' => $consignment['updated_at'] ?? null,
        ];
    }

    /** The consignment for one group, or why it can't be booked. */
    private function parcel(OrderStoreGroup $group): array|string
    {
        $group->loadMissing(['order.customer', 'items']);
        $order = $group->order;
        $phone = preg_replace('/\D+/', '', (string) $order->shipping_phone);
        if (strlen($phone) !== 11) return 'A valid 11-digit phone number is required for SteadFast delivery.';

        return [
            'invoice' => $group->number,
            'recipient_name' => $order->shipping_name,
            'recipient_phone' => $phone,
            'recipient_email' => $order->customer?->email,
            'recipient_address' => $order->shipping_address,
            'cod_amount' => (float) $group->cod_amount,
            'note' => $order->notes,
            'item_description' => $this->subOrders->itemDescription($group),
            'total_lot' => (int) $group->items->where('fulfillment_status', '!=', 'cancelled')->sum('quantity'),
            'delivery_type' => 0,
        ];
    }

    /** Whether a store has connected its own Steadfast account. */
    public static function storeConnected(?Seller $seller): bool
    {
        return $seller !== null && $seller->steadfast_connected_at !== null && filled($seller->steadfast_api_key) && filled($seller->steadfast_secret_key);
    }

    private function credentials(?Seller $seller = null): array
    {
        if ($seller) {
            if (!self::storeConnected($seller)) throw ValidationException::withMessages(['courier' => ['Connect your Steadfast account in Store settings first.']]);
            return [(string) $seller->steadfast_api_key, (string) $seller->steadfast_secret_key];
        }
        $apiKey = (string) config('services.steadfast.api_key');
        $secretKey = (string) config('services.steadfast.secret_key');
        if ($apiKey === '' || $secretKey === '') {
            throw ValidationException::withMessages(['courier' => ['SteadFast credentials are not configured.']]);
        }
        return [$apiKey, $secretKey];
    }
}
