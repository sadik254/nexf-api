<?php
namespace App\Http\Controllers;

use App\Models\CourierWebhookEvent;
use App\Models\OrderItem;
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
        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized webhook.'], 401);
        }

        $payload = $request->json()->all();
        $validator = Validator::make($payload, [
            'notification_type' => ['required', 'in:delivery_status,tracking_update'],
            'consignment_id' => ['required'], 'invoice' => ['required', 'string'],
            'tracking_message' => ['nullable', 'string'], 'status' => ['required_if:notification_type,delivery_status', 'string'],
        ]);
        if ($validator->fails()) return response()->json(['status' => 'error', 'message' => 'Invalid webhook payload.', 'errors' => $validator->errors()], 422);

        $hash = hash('sha256', $request->getContent());
        $event = CourierWebhookEvent::firstOrCreate(['event_hash' => $hash], [
            'provider' => 'steadfast', 'notification_type' => $payload['notification_type'],
            'consignment_id' => (string) $payload['consignment_id'], 'invoice' => $payload['invoice'], 'payload' => $payload,
        ]);
        if ($event->processed_at) return response()->json(['status' => 'success', 'message' => 'Webhook already processed.']);

        $item = OrderItem::query()->where('courier_consignment_id', (string) $payload['consignment_id'])->orWhere('courier_invoice', $payload['invoice'])->first();
        if (!$item) { $event->update(['processing_error' => 'Invalid consignment or invoice.']); return response()->json(['status' => 'error', 'message' => 'Invalid consignment ID.'], 422); }

        $item->update(['courier_status' => $payload['status'] ?? $item->courier_status, 'courier_tracking_message' => $payload['tracking_message'] ?? $item->courier_tracking_message, 'courier_updated_at' => now()]);
        $status = strtolower((string) ($payload['status'] ?? ''));
        if ($payload['notification_type'] === 'delivery_status' && $status === 'delivered' && !in_array($item->fulfillment_status, ['returned', 'cancelled'], true)) {
            $item->update(['fulfillment_status' => 'delivered', 'delivered_at' => now()]);
        } elseif ($payload['notification_type'] === 'delivery_status' && $status === 'cancelled' && !in_array($item->fulfillment_status, ['returned', 'cancelled'], true)) {
            $item->update(['fulfillment_status' => 'return_pending']);
        }
        $event->update(['processed_at' => now()]);
        return response()->json(['status' => 'success', 'message' => 'Webhook received successfully.']);
    }
}
