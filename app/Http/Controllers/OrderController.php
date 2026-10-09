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
use App\Services\StoreShippingService;
use App\Services\TurnstileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(private InventoryService $inventory, private CheckoutService $checkout, private OrderNotificationService $notifications, private SteadfastService $steadfast, private StoreShippingService $storeShipping, private TurnstileService $turnstile)
    {
    }

    public function indexCustomer(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        return response()->json($this->paginateOrderList($customer->orders()->with(['items', 'storeGroups'])->latest(), $request));
    }

    public function showCustomer(Request $request, Order $order): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        if ((int) $order->customer_id !== (int) $customer->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($order->load(['items', 'storeGroups', 'paymentMethod', 'shippingMethod', 'coupon']));
    }

    public function preview(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $data = $request->validate($this->checkoutRules());
        $preview = $this->checkout->preview($customer, $data + ['ip_address' => $request->ip()]);

        return response()->json([
            'items' => collect($preview['items'])->map(fn ($item) => [
                'product_id' => $item['product']->id, 'variation_id' => $item['variation']?->id,
                'name' => $item['product']->name, 'quantity' => $item['quantity'],
                'available_quantity' => $item['quote']['available_quantity'], 'line_subtotal' => $item['quote']['subtotal'],
            ])->values(),
            'payment_method' => $preview['paymentMethod'], 'shipping_method' => $preview['shippingMethod'],
            'coupon' => $preview['coupon'], 'subtotal' => $preview['subtotal'],
            'discount_total' => $preview['discount_total'],
            'shipping_groups' => $preview['shipping_groups'],
            'shipping_charge' => $preview['shipping_charge'], 'total' => $preview['total'],
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

        $query = Order::query()
            ->with(['customer', 'items', 'storeGroups'])
            ->whereDoesntHave('items', fn ($q) => $q->whereNotNull('seller_id'))
            ->latest();

        return response()->json($this->paginateOrderList($query, $request));
    }

    public function showAdmin(Request $request, Order $order): JsonResponse
    {
        if (!$request->user() instanceof Admin) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($order->items()->whereNotNull('seller_id')->exists()) {
            return response()->json(['message' => 'Seller orders are available through the super-admin seller-order endpoints.'], 403);
        }

        return response()->json($order->load(['customer', 'items', 'storeGroups', 'paymentMethod', 'shippingMethod', 'coupon']));
    }

    public function indexSellerOrdersForSuperAdmin(Request $request, Seller $seller): JsonResponse
    {
        if (!$request->user() instanceof Admin || $request->user()->role !== 'super_admin') return response()->json(['message' => 'Forbidden.'], 403);
        return response()->json($this->paginateOrderList(Order::whereHas('items', fn ($q) => $q->where('seller_id', $seller->id))->with(['customer', 'items' => fn ($q) => $q->where('seller_id', $seller->id), 'storeGroups' => fn ($q) => $q->where('seller_id', $seller->id)])->latest(), $request));
    }

    public function showSellerOrderForSuperAdmin(Request $request, Seller $seller, Order $order): JsonResponse
    {
        if (!$request->user() instanceof Admin || $request->user()->role !== 'super_admin') return response()->json(['message' => 'Forbidden.'], 403);
        if (!$order->items()->where('seller_id', $seller->id)->exists()) return response()->json(['message' => 'Order does not contain items from this seller.'], 404);
        return response()->json($order->load(['customer', 'items' => fn ($q) => $q->where('seller_id', $seller->id), 'storeGroups' => fn ($q) => $q->where('seller_id', $seller->id), 'paymentMethod', 'shippingMethod', 'coupon']));
    }

    public function cancelSellerOrderForSuperAdmin(Request $request, Seller $seller, Order $order): JsonResponse
    {
        if (!$request->user() instanceof Admin || $request->user()->role !== 'super_admin') return response()->json(['message' => 'Forbidden.'], 403);
        if (!$order->items()->where('seller_id', $seller->id)->exists()) return response()->json(['message' => 'Order does not contain items from this seller.'], 404);
        $order = $this->cancelOrder($order, $request->user());
        $this->notifications->cancelled($order);
        return response()->json(['message' => 'Seller order cancelled and inventory restored successfully.', 'order' => $order]);
    }

    public function indexSeller(Request $request): JsonResponse
    {
        /** @var Seller $seller */
        $seller = $request->user();
        return response()->json($this->paginateOrderList(
            Order::query()
                ->whereHas('items', fn ($query) => $query->where('seller_id', $seller->id))
                ->with([
                    'customer:id,name,email,phone',
                    'items' => fn ($query) => $query->where('seller_id', $seller->id),
                    'storeGroups' => fn ($query) => $query->where('seller_id', $seller->id),
                    'shippingMethod',
                ])
                ->latest(), $request
        ));
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
            'storeGroups' => fn ($query) => $query->where('seller_id', $seller->id),
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
            return response()->json(['message' => 'Order item does not belong to this order.'], 422);
        }

        return $this->fulfillItem($request, $order, $item);
    }

    public function fulfillSellerItemForSuperAdmin(Request $request, Seller $seller, Order $order, OrderItem $item): JsonResponse
    {
        if (!$request->user() instanceof Admin || $request->user()->role !== 'super_admin') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ((int) $item->order_id !== (int) $order->id || (int) $item->seller_id !== (int) $seller->id) {
            return response()->json(['message' => 'Order item does not belong to this seller order.'], 422);
        }

        return $this->fulfillItem($request, $order, $item, $seller->id);
    }

    public function reconcileReturn(Request $request, Order $order, OrderItem $item): JsonResponse
    {
        if (!$request->user() instanceof Admin || (int) $item->order_id !== (int) $order->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $result = DB::transaction(function () use ($order, $item, $request) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $lockedItem = OrderItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($lockedItem->fulfillment_status !== 'return_pending') {
                throw ValidationException::withMessages(['status' => ['Only items awaiting return reconciliation can be reconciled.']]);
            }
            $this->inventory->restoreOrderItem($lockedItem, $request->user(), 'return_reconciliation');
            $lockedItem->update(['fulfillment_status' => 'returned']);
            if (!$lockedOrder->items()->whereNotIn('fulfillment_status', ['returned', 'cancelled'])->exists()) {
                $lockedOrder->update(['status' => 'cancelled', 'cancelled_at' => $lockedOrder->cancelled_at ?? now()]);
            }
            return $lockedOrder->load(['customer', 'items', 'paymentMethod', 'shippingMethod', 'coupon']);
        });
        return response()->json(['message' => 'Returned item reconciled and inventory restored.', 'order' => $result]);
    }

    public function bulkShipAdmin(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof Admin) return response()->json(['message' => 'Forbidden.'], 403);
        $ids = $request->validate(['order_item_ids' => ['required', 'array', 'min:1', 'max:500'], 'order_item_ids.*' => ['integer', 'distinct', 'exists:order_items,id']])['order_item_ids'];
        $items = OrderItem::query()->with(['order.customer'])->whereIn('id', $ids)->get();
        if ($items->contains(fn ($item) => $item->seller_id !== null)) {
            return response()->json(['message' => 'Seller parcels must be fulfilled manually through the seller order endpoint.'], 422);
        }
        return $this->bulkShipItems($items, $ids);
    }

    private function bulkShipItems($items, array $requestedIds): JsonResponse
    {
        if ($items->count() !== count($requestedIds)) return response()->json(['message' => 'One or more items are outside your fulfilment scope.'], 403);
        $invalid = $items->first(fn ($item) => $item->fulfillment_status !== 'confirmed' || $item->courier_consignment_id);
        if ($invalid) return response()->json(['message' => 'All selected items must be confirmed and not already shipped.'], 422);

        $results = $this->steadfast->createBulkConsignments($items);
        $byInvoice = collect($results)->keyBy('invoice');
        $successful = [];
        $failed = [];
        DB::transaction(function () use ($items, $byInvoice, &$successful, &$failed) {
            foreach ($items as $item) {
                $invoice = $item->order->order_number . '-ITEM-' . $item->id;
                $result = $byInvoice->get($invoice);
                if (!$result || ($result['status'] ?? null) === 'error' || empty($result['tracking_code'])) {
                    $item->update(['courier_error' => $result['message'] ?? 'SteadFast did not create this consignment.']);
                    $failed[] = $item->id;
                    continue;
                }
                $item->update(['fulfillment_status' => 'shipped', 'tracking_number' => $result['tracking_code'], 'courier_provider' => 'steadfast', 'courier_consignment_id' => (string) ($result['consignment_id'] ?? ''), 'courier_invoice' => $result['invoice'] ?? $invoice, 'courier_status' => $result['status'] ?? 'in_review', 'shipped_at' => now(), 'courier_created_at' => $result['created_at'] ?? now(), 'courier_updated_at' => $result['updated_at'] ?? now(), 'courier_error' => null]);
                $this->refreshOrderStatus($item->order);
                $successful[] = $item->id;
            }
        });

        return response()->json(['message' => 'Bulk fulfilment processed.', 'successful_item_ids' => $successful, 'failed_item_ids' => $failed]);
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
                if (in_array($item->fulfillment_status, ['shipped', 'delivered', 'return_pending'], true)) {
                    throw ValidationException::withMessages(['order' => ['Shipped, delivered, or return-pending items must complete courier return reconciliation before cancellation.']]);
                }
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
            'courier_provider' => ['nullable', 'string', 'max:255'],
        ]);

        if ($sellerId !== null && $data['status'] === 'shipped' && empty($data['courier_provider'])) {
            throw ValidationException::withMessages([
                'courier_provider' => ['Parcel service is required when marking an item shipped.'],
            ]);
        }

        $order = DB::transaction(function () use ($order, $item, $data, $sellerId) {
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
            if ($sellerId === null && $data['status'] === 'shipped' && !$lockedItem->courier_consignment_id) {
                $courier = $this->steadfast->createConsignment($lockedItem);
            }

            $lockedItem->update([
                'fulfillment_status' => $data['status'],
                'tracking_number' => $courier['tracking_code'] ?? ($data['tracking_number'] ?? $lockedItem->tracking_number),
                'courier_provider' => $courier['provider'] ?? ($data['courier_provider'] ?? $lockedItem->courier_provider),
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

    public function refreshOrderStatus(Order $order): void
    {
        $statuses = $order->items()->pluck('fulfillment_status');
        if ($statuses->isEmpty()) {
            return;
        }

        if ($statuses->every(fn (string $status) => in_array($status, ['returned', 'cancelled'], true))) {
            $order->update(['status' => 'cancelled', 'cancelled_at' => $order->cancelled_at ?? now()]);
            return;
        }

        $rank = ['pending' => 0, 'confirmed' => 1, 'shipped' => 2, 'delivered' => 3, 'return_pending' => 4, 'returned' => 5];
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
            // Cloudflare Turnstile token - required once TURNSTILE_SECRET_KEY is set.
            'turnstile_token' => ['nullable', 'string', 'max:2048'],
        ]);

        $this->turnstile->assertHuman($request);

        // Uses the same validation, availability, and pricing rules as checkout preview.
        $data['ip_address'] = $request->ip();
        $preview = $this->checkout->preview($customer, $data);

        $order = DB::transaction(function () use ($customer, $data, $preview) {
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
                'reseller_id' => $customer->reseller_id,
                'payment_method_id' => $paymentMethod->id,
                'shipping_method_id' => $shippingMethod->id,
                'status' => 'pending',
                'payment_status' => $paymentMethod->code === 'cod' ? 'unpaid' : 'unpaid',
                'payment_method_code' => $paymentMethod->code,
                'payment_method_name' => $paymentMethod->name,
                'shipping_method_code' => $shippingMethod->code,
                'shipping_method_name' => $shippingMethod->name,
                'shipping_charge' => 0,
                'shipping_currency' => $shippingMethod->currency,
                'subtotal' => 0,
                'discount_total' => 0,
                'total' => 0,
                'shipping_name' => $data['shipping_name'],
                'shipping_phone' => $data['shipping_phone'],
                'ip_address' => $data['ip_address'],
                'shipping_address' => $data['shipping_address'],
                'notes' => $data['notes'] ?? null,
                'placed_at' => now(),
            ]);

            $subtotal = 0.0;
            $shippingLines = [];

            foreach ($data['items'] as $itemData) {
                $product = Product::query()
                    ->with('seller')
                    ->whereIn('status', ['active', 'unlisted'])
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
                $shippingLines[] = ['product' => $product, 'subtotal' => $lineSubtotal];
            }

            $shipping = $this->storeShipping->quote($shippingLines, $shippingMethod);
            $coupon = $preview['coupon'] ? Coupon::query()->lockForUpdate()->find($preview['coupon']->id) : null;
            if ($coupon && ($reason = $coupon->unusableReason())) {
                throw ValidationException::withMessages(['coupon_code' => [$reason]]);
            }
            if ($coupon && $coupon->per_customer_limit !== null && CouponRedemption::query()->where('coupon_id', $coupon->id)->where('customer_id', $customer->id)->count() >= $coupon->per_customer_limit) {
                throw ValidationException::withMessages(['coupon_code' => ['Coupon usage limit reached for this customer.']]);
            }
            $discountTotal = $coupon ? (float) $preview['discount_total'] : 0.0;
            $total = round($subtotal + $shipping['total'] - $discountTotal, 2);

            $order->storeGroups()->createMany($shipping['groups']);

            $order->fill([
                'coupon_id' => $coupon?->id,
                'coupon_code' => $coupon?->code,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'shipping_charge' => $shipping['total'],
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

            return $order->load(['items', 'storeGroups', 'paymentMethod', 'shippingMethod', 'coupon']);
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
            'device_id' => ['nullable', 'string', 'regex:/^[a-f0-9]{16,64}$/i'],
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

        if (!$coupon) {
            throw ValidationException::withMessages([
                'coupon_code' => ['Coupon code was not found.'],
            ]);
        }

        if ($reason = $coupon->unusableReason()) {
            throw ValidationException::withMessages([
                'coupon_code' => [$reason],
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

    private function paginateOrderList($query, Request $request): array
    {
        $filters = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'in:pending,confirmed,shipped,delivered,completed,cancelled,return_pending'],
            'payment_status' => ['sometimes', 'in:unpaid,paid,failed,refunded'],
            'search' => ['sometimes', 'string', 'max:100'],
        ]);
        if (isset($filters['payment_status'])) $query->where('payment_status', $filters['payment_status']);
        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(fn ($q) => $q->where('order_number', 'like', "%{$search}%")
                ->orWhere('shipping_name', 'like', "%{$search}%")
                ->orWhere('shipping_phone', 'like', "%{$search}%"));
        }
        $counts = (clone $query)->reorder()->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status')->map(fn ($count) => (int) $count)->all();
        if (isset($filters['status'])) $query->where('status', $filters['status']);
        $page = $query->paginate($filters['per_page'] ?? 25);
        return array_merge($page->toArray(), ['status_counts' => $counts]);
    }
}
