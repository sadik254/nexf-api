<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Persists the details for offline bKash, Nagad, Rocket and bank transfers.
 * Staff still verify the transfer before marking an order paid.
 */
class ManualPaymentController extends Controller
{
    private const METHODS = ['bkash', 'nagad', 'rocket', 'bank'];

    public function accounts(): JsonResponse
    {
        return response()->json(SiteSetting::find('payment_accounts')?->payload ?? []);
    }

    public function saveAccounts(Request $request): JsonResponse
    {
        abort_unless($request->user() instanceof Admin && $request->user()->role === 'super_admin', 403);

        $data = $request->validate([
            'accounts' => ['present', 'array', 'max:4'],
            'accounts.*.id' => ['required', Rule::in(self::METHODS), 'distinct'],
            'accounts.*.enabled' => ['required', 'boolean'],
            'accounts.*.number' => ['nullable', 'string', 'max:60'],
            'accounts.*.accountType' => ['nullable', Rule::in(['Personal', 'Agent', 'Merchant'])],
            'accounts.*.accountName' => ['nullable', 'string', 'max:120'],
            'accounts.*.bankName' => ['nullable', 'string', 'max:120'],
            'accounts.*.branch' => ['nullable', 'string', 'max:120'],
            'accounts.*.routing' => ['nullable', 'string', 'max:30'],
            'accounts.*.note' => ['nullable', 'string', 'max:300'],
        ]);

        foreach ($data['accounts'] as $index => $account) {
            if ($account['enabled'] && blank($account['number'] ?? null)) {
                throw ValidationException::withMessages(["accounts.{$index}.number" => 'A method that is on needs a number to pay to.']);
            }
        }

        // Laravel's empty-string-to-null middleware would otherwise
        // violate the frontend contract, where every account has a string
        // `number` field even while it is disabled.
        $data['accounts'] = array_map(function (array $account): array {
            $account['number'] = (string) ($account['number'] ?? '');
            return $account;
        }, $data['accounts']);

        SiteSetting::updateOrCreate(['key' => 'payment_accounts'], ['payload' => $data['accounts']]);

        return response()->json($data['accounts']);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeByPhone($request, $order);

        return response()->json($this->state($order));
    }

    public function submit(Request $request, Order $order): JsonResponse
    {
        $this->authorizeByPhone($request, $order);

        // A pending COD order may be paid in advance if the shopper picks an
        // enabled manual method in the payment window.
        $codPrepay = $order->payment_method_code === 'cod'
            && $order->status === 'pending'
            && $request->filled('method');
        abort_unless($codPrepay || in_array($order->payment_method_code, self::METHODS, true), 422, 'This order is not paid by manual transfer.');
        abort_if(in_array($order->status, ['cancelled', 'delivered'], true), 422, 'This order is closed.');

        $enabledMethods = collect(SiteSetting::find('payment_accounts')?->payload ?? [])
            ->filter(fn (array $account): bool => !empty($account['enabled']) && !empty($account['number']))
            ->pluck('id')
            ->all();
        $switch = $request->validate([
            'method' => ['nullable', Rule::in(array_values(array_intersect(self::METHODS, $enabledMethods)))],
        ]);

        if (!empty($switch['method']) && $switch['method'] !== $order->payment_method_code) {
            abort_if($order->status !== 'pending', 422, 'This order is already confirmed.');
            $method = PaymentMethod::query()->active()->where('code', $switch['method'])->firstOrFail();
            $order->forceFill([
                'payment_method_id' => $method->id,
                'payment_method_code' => $method->code,
                'payment_method_name' => $method->name,
            ]);
        }

        $data = $request->validate([
            // A reference is optional for bank transfers and wallet payments.
            'reference' => ['nullable', 'string', 'min:6', 'max:60', 'regex:/^[A-Za-z0-9-]+$/'],
            // Wallet transfers identify the sending number; bank transfers use
            // the shopper's bank name instead.
            'sender' => $order->payment_method_code === 'bank'
                ? ['required', 'string', 'min:2', 'max:40']
                : ['required', 'string', 'regex:/^(\+?88)?01[3-9]\d{8}$/'],
            'amount' => ['nullable', 'numeric', 'min:1', 'max:'.$order->total],
        ]);

        $order->forceFill([
            'payment_reference' => isset($data['reference']) ? strtoupper($data['reference']) : null,
            'payment_sender' => $data['sender'],
            'payment_amount' => $data['amount'] ?? $order->total,
            'payment_submitted_at' => now(),
        ])->save();

        return response()->json($this->state($order->fresh()));
    }

    public function clear(Order $order): JsonResponse
    {
        abort_unless(in_array($order->payment_method_code, self::METHODS, true), 422, 'This order is not paid by manual transfer.');

        $order->forceFill([
            'payment_reference' => null,
            'payment_sender' => null,
            'payment_amount' => null,
            'payment_submitted_at' => null,
            'paid_amount' => 0,
        ])->save();

        return response()->json([
            'message' => 'Payment deleted.',
            'order' => $order->fresh()->load(['customer', 'items', 'storeGroups', 'paymentMethod', 'shippingMethod', 'coupon']),
        ]);
    }

    private function authorizeByPhone(Request $request, Order $order): void
    {
        $digits = fn (?string $phone): string => substr(preg_replace('/\\D/', '', (string) $phone), -10);
        $phone = $request->input('phone', $request->query('phone'));

        abort_unless($phone && $digits($phone) === $digits($order->shipping_phone), 404);
    }

    private function state(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'payment_method_code' => $order->payment_method_code,
            'payment_method_name' => $order->payment_method_name,
            'total' => $order->total,
            'paid_amount' => $order->paid_amount,
            'payment_reference' => $order->payment_reference,
            'payment_sender' => $order->payment_sender,
            'payment_amount' => $order->payment_amount,
            'payment_submitted_at' => $order->payment_submitted_at,
            'payment_received_at' => $order->payment_received_at,
        ];
    }
}
