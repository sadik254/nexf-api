<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\ShippingMethod;
use App\Models\OrderItem;
use App\Models\Seller;
use App\Services\InventoryService;
use App\Services\CheckoutService;
use App\Services\OrderNotificationService;
use App\Services\SteadfastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(private InventoryService $inventory, private CheckoutService $checkout, private OrderNotificationService $notifications, private SteadfastService $steadfast)
    {
    }

    public function indexCustomer(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(1, min($perPage, 100));

        return response()->json(
            $customer->orders()
                ->with('items')
                ->latest()
                ->paginate($perPage)
        );
    }

    public function showCustomer(Request $request, Order $order): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        if ((int) $order->customer_id !== (int) $customer->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($order->load(['items', 'paymentMethod', 'shippingMethod', 'coupon']));
    }

    public function preview(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $data = $request->validate($this->checkoutRules());
        $preview = $this->checkout->preview($customer, $data);

        return response()->json([
            'items' => collect($preview['items'])->map(fn ($item) => [
                'product_id' => $item['product']->id, 'variation_id' => $item['variation']?->id,
                'name' => $item['product']->name, 'quantity' => $item['quantity'],
                'available_quantity' => $item['quote']['available_quantity'], 'line_subtotal' => $item['quote']['subtotal'],
            ])->values(),
            'payment_method' => $preview['paymentMethod'], 'shipping_method' => $preview['shippingMethod'],
            'coupon' => $preview['coupon'], 'subtotal' => $preview['subtotal'],
            'discount_total' => $preview['discount_total'], 'shipping_charge' => $preview['shippingMethod']->charge, 'total' => $preview['total'],
        ]);
    }

    public function cancelCustomer(Request $request, Order $order): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        if ((int) $order->customer_id !== (int) $customer->id) return response()->json(['message' => 'Forbidden.'], 403);
        if ($order->status !== 'pending') return response()->json(['message' => 'Only pending orders can be cancelled by customers.'], 422);
        $order = $this->cancelOrder($order, $customer);
        $this->notifications->cancelled($order);
        return response()->json(['message' => 'Order cancelled and inventory restored successfully.', 'order' => $order]);
    }

    public function indexAdmin(Request $request): JsonResponse
    {
        if (!$request->user() instanceof Admin) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(1, min($perPage, 100));
        $status = (string) $request->query('status', '');
        $paymentStatus = (string) $request->query('payment_status', '');
        $search = (string) $request->query('search', '');

        $query = Order::query()
            ->with(['customer', 'items'])
            ->latest();

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($paymentStatus !== '') {
            $query->where('payment_status', $paymentStatus);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('shipping_name', 'like', "%{$search}%")
                    ->orWhere('shipping_phone', 'like', "%{$search}%");
            });
        }

        return response()->json($query->paginate($perPage));
    }

    public function showAdmin(Request $request, Order $order): JsonResponse
    {
        if (!$request->user() instanceof Admin) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($order->load(['customer', 'items', 'paymentMethod', 'shippingMethod', 'coupon']));
    }

    public function indexSeller(Request $request): JsonResponse
    {
        /** @var Seller $seller */
        $seller = $request->user();
        $perPage = max(1, min((int) $request->query('per_page', 25), 100));

        return response()->json(
            Order::query()
                ->whereHas('items', fn ($query) => $query->where('seller_id', $seller->id))
                ->with([
                    'customer:id,name,email,phone',
                    'items' => fn ($query) => $query->where('seller_id', $seller->id),
                    'shippingMethod',
                ])
                ->latest()
                ->paginate($perPage)
        );
    }

    public function showSeller(Request $request, Order $order): JsonResponse
    {
        /** @var Seller $seller */
        $seller = $request->user();
        if (!$order->items()->where('seller_id', $seller->id)->exists()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($order->load([
            'customer:id,name,email,phone',
            'items' => fn ($query) => $query->where('seller_id', $seller->id),
            'shippingMethod',
        ]));
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        if (!$request->user() instanceof Admin) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json([
            'message' => 'Order status is derived from item fulfilment. Update an admin-owned item through the fulfilment endpoint.',
            'order' => $order->load(['customer', 'items', 'paymentMethod', 'shippingMethod', 'coupon']),
        ], 422);
    }

    public function fulfillSellerItem(Request $request, Order $order, OrderItem $item): JsonResponse
    {
        /** @var Seller $seller */
        $seller = $request->user();
        if ((int) $item->order_id !== (int) $order->id || (int) $item->seller_id !== (int) $seller->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $this->fulfillItem($request, $order, $item, $seller->id);
    }

    public function fulfillAdminItem(Request $request, Order $order, OrderItem $item): JsonResponse
    {
        if (!$request->user() instanceof Admin) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ((int) $item->order_id !== (int) $order->id || $item->seller_id !== null) {
            return response()->json(['message' => 'This item is not fulfilled by the admin store.'], 422);
        }

        return $this->fulfillItem($request, $order, $item);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();
        if (!$admin instanceof Admin) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $order = $this->cancelOrder($order, $admin);
        $this->notifications->cancelled($order);

        return response()->json([
            'message' => 'Order cancelled and inventory restored successfully.',
            'order' => $order,
        ]);
    }

    private function cancelOrder(Order $order, $actor): Order
    {
        return DB::transaction(function () use ($order, $actor) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($lockedOrder->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'order' => ['Order has already been cancelled.'],
                ]);
            }

            foreach ($lockedOrder->items as $item) {
                $this->inventory->restoreOrderItem($item, $actor);
                $item->update(['fulfillment_status' => 'cancelled']);
            }

            $lockedOrder->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);

            return $lockedOrder->load(['customer', 'items', 'paymentMethod', 'shippingMethod', 'coupon']);
        });
    }

    private function fulfillItem(Request $request, Order $order, OrderItem $item, ?int $sellerId = null): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:confirmed,shipped,delivered'],
            'tracking_number' => ['nullable', 'string', 'max:255'],
        ]);

        $order = DB::transaction(function () use ($order, $item, $data) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($lockedOrder->status === 'cancelled') {
                throw ValidationException::withMessages(['order' => ['Cancelled orders cannot be fulfilled.']]);
            }

            $lockedItem = OrderItem::query()->lockForUpdate()->findOrFail($item->id);
            $allowedNextStatus = [
                'pending' => 'confirmed',
                'confirmed' => 'shipped',
                'shipped' => 'delivered',
            ][$lockedItem->fulfillment_status] ?? null;

            if ($data['status'] !== $allowedNextStatus) {
                throw ValidationException::withMessages([
                    'status' => ["Item must transition from {$lockedItem->fulfillment_status} to {$allowedNextStatus}."],
                ]);
            }

            $courier = null;
            if ($data['status'] === 'shipped' && !$lockedItem->courier_consignment_id) {
                $courier = $this->steadfast->createConsignment($lockedItem);
            }

            $lockedItem->update([
                'fulfillment_status' => $data['status'],
                'tracking_number' => $courier['tracking_code'] ?? ($data['tracking_number'] ?? $lockedItem->tracking_number),
                'courier_provider' => $courier['provider'] ?? $lockedItem->courier_provider,
                'courier_consignment_id' => $courier['consignment_id'] ?? $lockedItem->courier_consignment_id,
                'courier_invoice' => $courier['invoice'] ?? $lockedItem->courier_invoice,
                'courier_status' => $courier['status'] ?? $lockedItem->courier_status,
                'courier_created_at' => $courier['created_at'] ?? $lockedItem->courier_created_at,
                'courier_updated_at' => $courier['updated_at'] ?? $lockedItem->courier_updated_at,
                'shipped_at' => $data['status'] === 'shipped' ? now() : $lockedItem->shipped_at,
                'delivered_at' => $data['status'] === 'delivered' ? now() : $lockedItem->delivered_at,
            ]);

            $this->refreshOrderStatus($lockedOrder);

            return $lockedOrder->load(['customer', 'items', 'paymentMethod', 'shippingMethod', 'coupon']);
        });

        $this->notifications->statusChanged($order);

        if ($sellerId !== null) {
            $order->load([
                'customer:id,name,email,phone',
                'items' => fn ($query) => $query->where('seller_id', $sellerId),
                'shippingMethod',
            ]);
        }

        return response()->json([
            'message' => 'Item fulfilment updated successfully.',
            'order' => $order,
        ]);
    }

    private function refreshOrderStatus(Order $order): void
    {
        $statuses = $order->items()->pluck('fulfillment_status');
        if ($statuses->isEmpty()) {
            return;
        }

        if ($statuses->every(fn (string $status) => $status === 'cancelled')) {
            $order->update(['status' => 'cancelled', 'cancelled_at' => $order->cancelled_at ?? now()]);
            return;
        }

        $rank = ['pending' => 0, 'confirmed' => 1, 'shipped' => 2, 'delivered' => 3];
        $activeStatuses = $statuses->reject(fn (string $status) => $status === 'cancelled');
        $lowest = $activeStatuses->sortBy(fn (string $status) => $rank[$status] ?? 0)->first();
        $order->update(['status' => $lowest]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $data = $request->validate($this->checkoutRules() + [
            'shipping_name' => ['required', 'string', 'max:255'],
            'shipping_phone' => ['required', 'string', 'max:32'],
            'shipping_address' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        // Uses the same validation, availability, and pricing rules as checkout preview.
        $this->checkout->preview($customer, $data);

        $order = DB::transaction(function () use ($customer, $data) {
            $paymentMethod = PaymentMethod::query()->active()->find($data['payment_method_id']);
            if (!$paymentMethod) {
                throw ValidationException::withMessages([
                    'payment_method_id' => ['Selected payment method is unavailable.'],
                ]);
            }

            $shippingMethod = ShippingMethod::query()->active()->find($data['shipping_method_id']);
            if (!$shippingMethod) {
                throw ValidationException::withMessages([
                    'shipping_method_id' => ['Selected shipping method is unavailable.'],
                ]);
            }

            $order = Order::create([
                'order_number' => $this->generateOrderNumber(),
                'customer_id' => $customer->id,
                'payment_method_id' => $paymentMethod->id,
                'shipping_method_id' => $shippingMethod->id,
                'status' => 'pending',
                'payment_status' => $paymentMethod->code === 'cod' ? 'unpaid' : 'unpaid',
                'payment_method_code' => $paymentMethod->code,
                'payment_method_name' => $paymentMethod->name,
                'shipping_method_code' => $shippingMethod->code,
                'shipping_method_name' => $shippingMethod->name,
                'shipping_charge' => $shippingMethod->charge,
                'shipping_currency' => $shippingMethod->currency,
                'subtotal' => 0,
                'discount_total' => 0,
                'total' => 0,
                'shipping_name' => $data['shipping_name'],
                'shipping_phone' => $data['shipping_phone'],
                'shipping_address' => $data['shipping_address'],
                'notes' => $data['notes'] ?? null,
                'placed_at' => now(),
            ]);

            $subtotal = 0.0;

            foreach ($data['items'] as $itemData) {
                $product = Product::query()
                    ->with('seller')
                    ->where('status', 'active')
                    ->find($itemData['product_id']);

                if (!$product) {
                    throw ValidationException::withMessages([
                        'items' => ['One or more products are unavailable.'],
                    ]);
                }

                if ($product->seller_id !== null && (!$product->seller || $product->seller->status !== 'approved' || !$product->seller->is_active)) {
                    throw ValidationException::withMessages([
                        'items' => ["Product {$product->name} is unavailable."],
                    ]);
                }

                $quantity = (int) $itemData['quantity'];
                $variation = null;

                if ($product->product_type === 'variable' && empty($itemData['variation_id'])) {
                    throw ValidationException::withMessages([
                        'items' => ["A variation must be selected for {$product->name}."],
                    ]);
                }
                if ($product->product_type === 'simple' && !empty($itemData['variation_id'])) {
                    throw ValidationException::withMessages([
                        'items' => ["{$product->name} does not have purchasable variations."],
                    ]);
                }

                if (!empty($itemData['variation_id'])) {
                    $variation = ProductVariation::query()
                        ->where('product_id', $product->id)
                        ->where('is_active', true)
                        ->find($itemData['variation_id']);

                    if (!$variation) {
                        throw ValidationException::withMessages([
                            'items' => ["Selected variation for {$product->name} is unavailable."],
                        ]);
                    }

                    $result = $this->inventory->consumeVariation($variation, $quantity, $customer, 'order_sale', [
                        'order_id' => $order->id,
                    ]);
                } else {
                    $result = $this->inventory->consumeProduct($product, $quantity, $customer, 'order_sale', [
                        'order_id' => $order->id,
                    ]);
                }

                $lineSubtotal = (float) $result['totals']['revenue'];
                $lineCost = (float) $result['totals']['cost'];
                $lineProfit = (float) $result['totals']['profit'];
                $unitSellingPrice = round($lineSubtotal / $quantity, 2);
                $unitBuyingPrice = round($lineCost / $quantity, 2);

                $order->items()->create([
                    'product_id' => $product->id,
                    'variation_id' => $variation?->id,
                    'seller_id' => $product->seller_id,
                    'product_name' => $product->name,
                    'product_slug' => $product->slug,
                    'product_thumbnail' => $product->thumbnail,
                    'product_gallery' => $product->gallery,
                    'product_image_variants' => $product->image_variants,
                    'sku' => $variation?->sku,
                    'variation_attributes' => $variation?->attributes,
                    'quantity' => $quantity,
                    'unit_selling_price' => $unitSellingPrice,
                    'unit_buying_price' => $unitBuyingPrice,
                    'line_subtotal' => $lineSubtotal,
                    'line_cost' => $lineCost,
                    'line_profit' => $lineProfit,
                    'lot_allocations' => $result['allocations'],
                ]);

                $subtotal = round($subtotal + $lineSubtotal, 2);
            }

            $coupon = $this->resolveCoupon($data['coupon_code'] ?? null, $subtotal, $customer);
            $discountTotal = $coupon ? $coupon->discountForSubtotal($subtotal) : 0.0;
            $total = round($subtotal + (float) $shippingMethod->charge - $discountTotal, 2);

            $order->fill([
                'coupon_id' => $coupon?->id,
                'coupon_code' => $coupon?->code,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'total' => $total,
            ])->save();

            if ($coupon) {
                $coupon->increment('used_count');

                CouponRedemption::create([
                    'coupon_id' => $coupon->id,
                    'customer_id' => $customer->id,
                    'order_id' => $order->id,
                    'coupon_code' => $coupon->code,
                    'order_subtotal' => $subtotal,
                    'discount_amount' => $discountTotal,
                ]);
            }

            return $order->load(['items', 'paymentMethod', 'shippingMethod', 'coupon']);
        });

        $this->notifications->placed($order->load(['customer', 'items.seller']));

        return response()->json([
            'message' => 'Order placed successfully.',
            'order' => $order,
        ], 201);
    }

    private function checkoutRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variation_id' => ['nullable', 'integer', 'exists:product_variations,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'shipping_method_id' => ['required', 'integer', 'exists:shipping_methods,id'],
            'coupon_code' => ['nullable', 'string', 'max:64'],
        ];
    }

    private function resolveCoupon(?string $couponCode, float $subtotal, Customer $customer): ?Coupon
    {
        if (!$couponCode) {
            return null;
        }

        $code = (string) preg_replace('/[^A-Z0-9_-]/', '', Str::upper($couponCode));
        $coupon = Coupon::query()
            ->where('code', $code)
            ->lockForUpdate()
            ->first();

        if (!$coupon || !$coupon->isUsable()) {
            throw ValidationException::withMessages([
                'coupon_code' => ['Coupon is invalid or expired.'],
            ]);
        }

        if ($coupon->minimum_order_amount !== null && $subtotal < (float) $coupon->minimum_order_amount) {
            throw ValidationException::withMessages([
                'coupon_code' => ['Minimum order amount not reached for this coupon.'],
            ]);
        }

        if ($coupon->per_customer_limit !== null) {
            $usedByCustomer = CouponRedemption::where('coupon_id', $coupon->id)
                ->where('customer_id', $customer->id)
                ->count();

            if ($usedByCustomer >= $coupon->per_customer_limit) {
                throw ValidationException::withMessages([
                    'coupon_code' => ['Coupon usage limit reached for this customer.'],
                ]);
            }
        }

        return $coupon;
    }

    private function generateOrderNumber(): string
    {
        do {
            $orderNumber = 'ORD-' . now()->format('Ymd') . '-' . Str::upper(Str::random(8));
        } while (Order::where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }
}
