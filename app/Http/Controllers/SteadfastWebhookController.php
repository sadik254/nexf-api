<?php
namespace App\Http\Controllers;

use App\Models\CourierWebhookEvent;
use App\Models\OrderItem;
use App\Models\OrderStoreGroup;
use App\Models\Seller;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SteadfastWebhookController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    public function handle(Request $request): JsonResponse
    {
        $expected = (string) (config('services.steadfast.webhook_token') ?: config('services.steadfast.api_key'));
        $provided = preg_replace('/^Bearer\s+/i', '', (string) $request->header('Authorization'));
        // NEXF's own account, or a store's own Steadfast account (its token from Store settings).
        $store = $provided !== '' && ($expected === '' || !hash_equals($expected, $provided))
            ? Seller::query()->whereNotNull('steadfast_connected_at')->where('steadfast_webhook_token', $provided)->first()
            : null;
        if (!$store && ($expected === '' || $provided === '' || !hash_equals($expected, $provided))) {
            // Steadfast application-log output intentionally disabled after integration.
            // Log::warning('Steadfast webhook rejected', ['ip' => $request->ip(), 'reason' => 'unauthorized']);
            return response()->json(['status' => 'error', 'message' => 'Unauthorized webhook.'], 401);
        }

        $payload = $request->json()->all();
        $validator = Validator::make($payload, [
            'notification_type' => ['required', 'in:delivery_status,tracking_update'],
            'consignment_id' => ['required'], 'invoice' => ['required', 'string'],
            'tracking_message' => ['nullable', 'string'], 'status' => ['required_if:notification_type,delivery_status', 'string'],
        ]);
        if ($validator->fails()) {
            // Steadfast application-log output intentionally disabled after integration.
            // Log::warning('Steadfast webhook rejected', ['ip' => $request->ip(), 'reason' => 'invalid_payload', 'payload' => $payload, 'errors' => $validator->errors()->toArray()]);
            return response()->json(['status' => 'error', 'message' => 'Invalid webhook payload.', 'errors' => $validator->errors()], 422);
        }

        // Durable webhook records remain in courier_webhook_events; application-log
        // output is intentionally disabled after integration.
        // Log::info('Steadfast webhook received', ['ip' => $request->ip(), 'payload' => $payload]);

        $hash = hash('sha256', $request->getContent());
        $event = CourierWebhookEvent::firstOrCreate(['event_hash' => $hash], [
            'provider' => 'steadfast', 'notification_type' => $payload['notification_type'],
            'consignment_id' => (string) $payload['consignment_id'], 'invoice' => $payload['invoice'], 'payload' => $payload,
        ]);
        if ($event->processed_at) {
            // Log::info('Steadfast webhook ignored', ['event_id' => $event->id, 'reason' => 'duplicate']);
            return response()->json(['status' => 'success', 'message' => 'Webhook already processed.']);
        }

        // A parcel is a store group; parcels booked before sub-orders are a single line.
        // A store's token only reaches that store's own parcels.
        $group = OrderStoreGroup::query()
            ->where(fn ($q) => $q->where('courier_consignment_id', (string) $payload['consignment_id'])->orWhere('courier_invoice', $payload['invoice']))
            ->when($store, fn ($q) => $q->where('seller_id', $store->id)->where('ships_by', 'seller'), fn ($q) => $q->where('ships_by', 'nexf'))
            ->first();
        $items = $group
            ? $group->items()->where('fulfillment_status', '!=', 'cancelled')->get()
            // Parcels booked one line at a time before sub-orders (`…-ITEM-…` invoices) are found by line.
            : ($store ? collect() : OrderItem::query()
                ->where(fn ($q) => $q->where('courier_consignment_id', (string) $payload['consignment_id'])->orWhere('courier_invoice', $payload['invoice']))
                ->where(fn ($q) => $q->whereNull('store_group_id')->orWhere('courier_invoice', 'like', '%-ITEM-%')->orWhereHas('storeGroup', fn ($g) => $g->whereNull('courier_consignment_id')))
                ->limit(1)->get());
        $item = $items->first();
        if (!$item) {
            $event->update([
                'processing_error' => 'No matching local consignment; event ignored.',
                'processed_at' => now(),
            ]);

            // Log::warning('Steadfast webhook ignored', ['event_id' => $event->id, 'reason' => 'no_matching_consignment']);

            return response()->json([
                'status' => 'success',
                'message' => 'Webhook received successfully.',
            ]);
        }

        $status = strtolower((string) ($payload['status'] ?? ''));
        $group?->update(['courier_status' => $payload['status'] ?? $group->courier_status]);
        foreach ($items as $line) {
            $line->update(['courier_status' => $payload['status'] ?? $line->courier_status, 'courier_tracking_message' => $payload['tracking_message'] ?? $line->courier_tracking_message, 'courier_updated_at' => now()]);
            if ($payload['notification_type'] === 'delivery_status' && $status === 'delivered' && !in_array($line->fulfillment_status, ['returned', 'cancelled'], true)) {
                $line->update(['fulfillment_status' => 'delivered', 'delivered_at' => now()]);
            } elseif ($payload['notification_type'] === 'delivery_status' && $status === 'cancelled' && !in_array($line->fulfillment_status, ['returned', 'cancelled'], true)) {
                $line->update(['fulfillment_status' => 'return_pending']);
            }
        }
        app(OrderController::class)->refreshOrderStatus($item->order);
        $event->update(['processed_at' => now()]);
        // Log::info('Steadfast webhook processed', ['event_id' => $event->id, 'order_item_id' => $item->id, 'fulfillment_status' => $item->fresh()->fulfillment_status]);
        return response()->json(['status' => 'success', 'message' => 'Webhook received successfully.']);
    }
}
