<?php

namespace App\Http\Controllers;

use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A store's own Steadfast account (store owner only). Connected, packing the
 * store's parcel books it on that account; the keys never leave the server.
 */
class SellerCourierController extends Controller
{
    /** GET /seller/courier */
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->state($this->owner($request)));
    }

    /** POST /seller/courier/steadfast {api_key, secret_key} - checked with Steadfast before saving. */
    public function connect(Request $request): JsonResponse
    {
        $seller = $this->owner($request);
        $data = $request->validate([
            'api_key' => ['required', 'string', 'max:255'],
            'secret_key' => ['required', 'string', 'max:255'],
        ]);
        $apiKey = trim($data['api_key']);
        $secretKey = trim($data['secret_key']);

        // Steadfast's balance call is the cheapest way to prove the keys work.
        try {
            $response = Http::timeout(config('services.steadfast.timeout', 15))->acceptJson()
                ->withHeaders(['Api-Key' => $apiKey, 'Secret-Key' => $secretKey])
                ->get(rtrim((string) config('services.steadfast.base_url'), '/') . '/get_balance');
        } catch (\Throwable) {
            throw ValidationException::withMessages(['api_key' => ["Couldn't reach Steadfast. Try again in a moment."]]);
        }
        if (!$response->successful() || (int) ($response->json('status') ?? 0) !== 200) {
            throw ValidationException::withMessages(['api_key' => ['Steadfast did not accept these keys.']]);
        }

        $seller->forceFill([
            'steadfast_api_key' => $apiKey,
            'steadfast_secret_key' => $secretKey,
            'steadfast_webhook_token' => $seller->steadfast_webhook_token ?: Str::random(48),
            'steadfast_connected_at' => now(),
        ])->save();

        return response()->json($this->state($seller->fresh()));
    }

    /** POST /seller/courier/steadfast/delete */
    public function disconnect(Request $request): JsonResponse
    {
        $seller = $this->owner($request);
        $seller->forceFill(['steadfast_api_key' => null, 'steadfast_secret_key' => null, 'steadfast_connected_at' => null])->save();
        return response()->json($this->state($seller->fresh()));
    }

    private function state(Seller $seller): array
    {
        $connected = $seller->steadfast_connected_at !== null && filled($seller->steadfast_api_key);
        return ['steadfast' => [
            'connected' => $connected,
            'connected_at' => $seller->steadfast_connected_at,
            // Enough to recognise the key, never the key itself.
            'api_key_hint' => $connected ? '••••' . substr((string) $seller->steadfast_api_key, -4) : null,
            // Paste these into Steadfast so its delivery updates reach the store's orders.
            'webhook_url' => rtrim((string) config('app.url'), '/') . '/api/webhooks/steadfast',
            'webhook_token' => $connected ? $seller->steadfast_webhook_token : null,
        ]];
    }

    private function owner(Request $request): Seller
    {
        abort_if($request->attributes->get('seller_staff'), 403, 'Only the store owner can manage courier keys.');
        $seller = $request->user();
        abort_unless($seller instanceof Seller, 403);
        return $seller;
    }
}
