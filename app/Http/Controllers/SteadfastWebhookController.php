<?php
namespace App\Http\Controllers;

use App\Models\CourierWebhookEvent;
use App\Models\OrderItem;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class SteadfastWebhookController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    public function handle(Request $request): JsonResponse
    {
        $expected = (string) (config('services.steadfast.webhook_token') ?: config('services.steadfast.api_key'));
        $provided = preg_replace('/^Bearer\s+/i', '', (string) $request->header('Authorization'));
        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            // STEADFAST_LOGGING: records rejected callbacks without logging the token.
            Log::warning('Steadfast webhook rejected', ['ip' => $request->ip(), 'reason' => 'unauthorized']);
            return response()->json(['status' => 'error', 'message' => 'Unauthorized webhook.'], 401);
        }

        $payload = $request->json()->all();
        $validator = Validator::make($payload, [
            'notification_type' => ['required', 'in:delivery_status,tracking_update'],
            'consignment_id' => ['required'], 'invoice' => ['required', 'string'],
            'tracking_message' => ['nullable', 'string'], 'status' => ['required_if:notification_type,delivery_status', 'string'],
        ]);
        if ($validator->fails()) {
            // STEADFAST_LOGGING: records invalid callbacks for courier debugging.
            Log::warning('Steadfast webhook rejected', ['ip' => $request->ip(), 'reason' => 'invalid_payload', 'payload' => $payload, 'errors' => $validator->errors()->toArray()]);
            return response()->json(['status' => 'error', 'message' => 'Invalid webhook payload.', 'errors' => $validator->errors()], 422);
        }

        // STEADFAST_LOGGING: logs every authenticated callback. The full event
        // is also retained in courier_webhook_events for durable inspection.
        Log::info('Steadfast webhook received', ['ip' => $request->ip(), 'payload' => $payload]);

        $hash = hash('sha256', $request->getContent());
        $event = CourierWebhookEvent::firstOrCreate(['event_hash' => $hash], [
            'provider' => 'steadfast', 'notification_type' => $payload['notification_type'],
            'consignment_id' => (string) $payload['consignment_id'], 'invoice' => $payload['invoice'], 'payload' => $payload,
        ]);
        if ($event->processed_at) {
            Log::info('Steadfast webhook ignored', ['event_id' => $event->id, 'reason' => 'duplicate']);
            return response()->json(['status' => 'success', 'message' => 'Webhook already processed.']);
        }

        $item = OrderItem::query()->where('courier_consignment_id', (string) $payload['consignment_id'])->orWhere('courier_invoice', $payload['invoice'])->first();
        if (!$item) {
            $event->update([
                'processing_error' => 'No matching local consignment; event ignored.',
                'processed_at' => now(),
            ]);

            Log::warning('Steadfast webhook ignored', ['event_id' => $event->id, 'reason' => 'no_matching_consignment']);

            return response()->json([
                'status' => 'success',
                'message' => 'Webhook received successfully.',
            ]);
        }

        $item->update(['courier_status' => $payload['status'] ?? $item->courier_status, 'courier_tracking_message' => $payload['tracking_message'] ?? $item->courier_tracking_message, 'courier_updated_at' => now()]);
        $status = strtolower((string) ($payload['status'] ?? ''));
        if ($payload['notification_type'] === 'delivery_status' && $status === 'delivered' && !in_array($item->fulfillment_status, ['returned', 'cancelled'], true)) {
            $item->update(['fulfillment_status' => 'delivered', 'delivered_at' => now()]);
        } elseif ($payload['notification_type'] === 'delivery_status' && $status === 'cancelled' && !in_array($item->fulfillment_status, ['returned', 'cancelled'], true)) {
            $item->update(['fulfillment_status' => 'return_pending']);
        }
        app(OrderController::class)->refreshOrderStatus($item->order);
        $event->update(['processed_at' => now()]);
        Log::info('Steadfast webhook processed', ['event_id' => $event->id, 'order_item_id' => $item->id, 'fulfillment_status' => $item->fresh()->fulfillment_status]);
        return response()->json(['status' => 'success', 'message' => 'Webhook received successfully.']);
    }
}
