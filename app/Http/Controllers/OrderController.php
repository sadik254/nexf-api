<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ReturnRequest;
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

    public function previewAdmin(Request $request): JsonResponse
    {
        if (!$request->user() instanceof Admin) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'editing_order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'shipping_name' => ['required', 'string', 'max:255'],
            'shipping_phone' => ['required', 'string', 'max:32'],
            'shipping_address' => ['required', 'string'],
        ] + $this->checkoutRules());

        $customer = !empty($data['customer_id']) ? Customer::findOrFail($data['customer_id']) : null;
        if (!empty($data['editing_order_id'])) $data['exclude_order_id'] = (int) $data['editing_order_id'];
        unset($data['editing_order_id']);
        $preview = $this->checkout->preview($customer, $data + ['ip_address' => $request->ip()]);

        return $this->checkoutPreviewResponse($preview);
    }

    public function preview(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $data = $request->validate($this->checkoutRules());
        $preview = $this->checkout->preview($customer, $data + ['ip_address' => $request->ip()]);

        return $this->checkoutPreviewResponse($preview);
    }

    private function checkoutPreviewResponse(array $preview): JsonResponse
    {
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
        if ($request->exists('items') || $request->exists('payment_method_id') || $request->exists('shipping_method_id') || $request->exists('coupon_code') || $request->exists('customer_id')) {
            return $this->replacePendingAdminOrder($request, $order);
        }
        $data = $request->validate([
            'paid_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'internal_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_guest' => ['sometimes', 'boolean'],
            'manual_ship' => ['sometimes', 'boolean'],
            'exchange_for' => ['sometimes', 'nullable', 'string', 'max:80'],
            'packed' => ['sometimes', 'boolean'],
            'shipping_name' => ['sometimes', 'string', 'max:255'],
            'shipping_phone' => ['sometimes', 'string', 'max:32'],
            'shipping_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'shipping_address' => ['sometimes', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);
        if (array_key_exists('packed', $data)) {
            if ($data['packed'] && $order->status !== 'confirmed') {
                throw ValidationException::withMessages(['packed' => ['An order must be confirmed before it can be packed.']]);
            }
            $data['packed_at'] = $data['packed'] ? now() : null;
            unset($data['packed']);
        }
        $order->update($data);
        return response()->json([
            'message' => 'Order details updated successfully. Fulfilment status is derived from item fulfilment.',
            'order' => $order->fresh()->load(['customer', 'items', 'storeGroups', 'paymentMethod', 'shippingMethod', 'coupon']),
        ]);
    }

    private function replacePendingAdminOrder(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'guest' => ['sometimes', 'boolean'],
            'shipping_name' => ['required', 'string', 'max:255'],
            'shipping_phone' => ['required', 'string', 'max:32'],
            'shipping_email' => ['nullable', 'email', 'max:255'],
            'shipping_address' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
            'internal_note' => ['nullable', 'string', 'max:5000'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'manual_discount_kind' => ['nullable', 'in:flat,percent'],
            'manual_discount_value' => ['nullable', 'numeric', 'min:0'],
        ] + $this->checkoutRules());

        $customer = !empty($data['customer_id']) ? Customer::findOrFail($data['customer_id']) : null;
        $data['ip_address'] = $request->ip();
        $data['exclude_order_id'] = $order->id;
        $actor = $request->user();

        $updated = DB::transaction(function () use ($order, $data, $customer, $actor) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $fulfillmentStates = $locked->items()->pluck('fulfillment_status')->unique()->values();
            if (!in_array($locked->status, ['pending', 'confirmed'], true) || $fulfillmentStates->count() > 1 || ($fulfillmentStates->isNotEmpty() && !in_array($fulfillmentStates->first(), ['pending', 'confirmed'], true))) {
                throw ValidationException::withMessages(['order' => ['Only pending or fully confirmed orders can be edited before shipping.']]);
            }
            $itemFulfillmentState = $fulfillmentStates->first() ?? 'pending';
            if ($locked->items()->whereNotNull('seller_id')->exists()) {
                throw ValidationException::withMessages(['order' => ['Marketplace orders cannot be edited through the house order editor.']]);
            }
            if ($locked->items()->whereHas('review')->exists() || DB::table('return_requests')->whereIn('order_item_id', $locked->items()->select('id'))->exists()) {
                throw ValidationException::withMessages(['order' => ['This order has review or return history and cannot have its lines replaced.']]);
            }

            foreach ($locked->items as $oldItem) {
                $this->inventory->restoreOrderItem($oldItem, $actor, 'admin_order_edit_restore');
            }

            // Requote only after the old lot allocations are restored, so unchanged quantities remain available.
            $preview = $this->checkout->preview($customer, $data);

            $oldRedemption = CouponRedemption::query()->where('order_id', $locked->id)->lockForUpdate()->first();
            if ($oldRedemption) {
                Coupon::query()->whereKey($oldRedemption->coupon_id)->decrement('used_count');
                $oldRedemption->delete();
            }
            $locked->items()->delete();
            $locked->storeGroups()->delete();

            $subtotal = 0.0;
            $shippingLines = [];
            foreach ($data['items'] as $itemData) {
                $product = Product::query()->with('seller')->availableForSale()->find($itemData['product_id']);
                if (!$product || ($product->seller_id !== null && (!$product->seller || $product->seller->status !== 'approved' || !$product->seller->is_active))) {
                    throw ValidationException::withMessages(['items' => ['One or more products are unavailable.']]);
                }
                $quantity = (int) $itemData['quantity'];
                $variation = null;
                if ($product->product_type === 'variable' && empty($itemData['variation_id'])) {
                    throw ValidationException::withMessages(['items' => ["A variation must be selected for {$product->name}."]]);
                }
                if ($product->product_type === 'simple' && !empty($itemData['variation_id'])) {
                    throw ValidationException::withMessages(['items' => ["{$product->name} does not have purchasable variations."]]);
                }
                if (!empty($itemData['variation_id'])) {
                    $variation = ProductVariation::query()->where('product_id', $product->id)->where('is_active', true)->find($itemData['variation_id']);
                    if (!$variation) throw ValidationException::withMessages(['items' => ["Selected variation for {$product->name} is unavailable."]]);
                    $result = $this->inventory->consumeVariation($variation, $quantity, $actor, 'order_sale', ['order_id' => $locked->id]);
                } else {
                    $result = $this->inventory->consumeProduct($product, $quantity, $actor, 'order_sale', ['order_id' => $locked->id]);
                }
                $lineSubtotal = (float) $result['totals']['revenue'];
                $lineCost = (float) $result['totals']['cost'];
                $locked->items()->create([
                    'product_id' => $product->id, 'variation_id' => $variation?->id, 'seller_id' => $product->seller_id,
                    'product_name' => $product->name, 'product_slug' => $product->slug, 'product_thumbnail' => $product->thumbnail,
                    'product_gallery' => $product->gallery, 'product_image_variants' => $product->image_variants,
                    'sku' => $variation?->sku, 'variation_attributes' => $variation?->attributes, 'quantity' => $quantity,
                    'unit_selling_price' => round($lineSubtotal / $quantity, 2), 'unit_buying_price' => round($lineCost / $quantity, 2),
                    'line_subtotal' => $lineSubtotal, 'line_cost' => $lineCost, 'line_profit' => (float) $result['totals']['profit'],
                    'lot_allocations' => $result['allocations'], 'fulfillment_status' => $itemFulfillmentState,
                ]);
                $subtotal = round($subtotal + $lineSubtotal, 2);
                $shippingLines[] = ['product' => $product, 'subtotal' => $lineSubtotal];
            }

            $paymentMethod = PaymentMethod::query()->active()->findOrFail($data['payment_method_id']);
            $shippingMethod = ShippingMethod::query()->whereNull('seller_id')->where('is_store_option',false)->active()->findOrFail($data['shipping_method_id']);
            $shipping = $this->storeShipping->quote($shippingLines, $shippingMethod, $data['store_shipping_methods'] ?? []);
            $coupon = $preview['coupon'] ? Coupon::query()->lockForUpdate()->find($preview['coupon']->id) : null;
            if ($coupon && ($reason = $coupon->unusableReason())) throw ValidationException::withMessages(['coupon_code' => [$reason]]);
            if ($coupon && $customer && $coupon->per_customer_limit !== null && CouponRedemption::query()->where('coupon_id', $coupon->id)->where('customer_id', $customer->id)->count() >= $coupon->per_customer_limit) {
                throw ValidationException::withMessages(['coupon_code' => ['Coupon usage limit reached for this customer.']]);
            }
            $couponDiscount = $coupon ? (float) $preview['discount_total'] : 0.0;
            $manualDiscount = $coupon ? 0.0 : $this->manualDiscountAmount($subtotal, $data['manual_discount_kind'] ?? null, (float) ($data['manual_discount_value'] ?? 0));
            $discount = round($couponDiscount + $manualDiscount, 2);
            $locked->storeGroups()->createMany(collect($shipping['groups'])->map(function (array $group) {
                unset($group['shipping_options']);
                return $group;
            })->all());
            $locked->fill([
                'customer_id' => $customer?->id, 'reseller_id' => $customer?->reseller_id,
                'is_guest' => empty($data['customer_id']) || (bool) ($data['guest'] ?? false), 'status' => $itemFulfillmentState,
                'payment_method_id' => $paymentMethod->id, 'payment_method_code' => $paymentMethod->code, 'payment_method_name' => $paymentMethod->name,
                'shipping_method_id' => $shippingMethod->id, 'shipping_method_code' => $shippingMethod->code, 'shipping_method_name' => $shippingMethod->name,
                'shipping_currency' => $shippingMethod->currency, 'shipping_name' => $data['shipping_name'], 'shipping_phone' => $data['shipping_phone'],
                'shipping_email' => $data['shipping_email'] ?? $customer?->email, 'shipping_address' => $data['shipping_address'],
                'notes' => $data['notes'] ?? null, 'internal_note' => $data['internal_note'] ?? null, 'paid_amount' => $data['paid_amount'] ?? null,
                'coupon_id' => $coupon?->id, 'coupon_code' => $coupon?->code, 'manual_discount_kind' => $data['manual_discount_kind'] ?? null,
                'manual_discount_value' => $data['manual_discount_value'] ?? null, 'subtotal' => $subtotal,
                'discount_total' => $discount, 'shipping_charge' => $shipping['total'], 'total' => round($subtotal + $shipping['total'] - $discount, 2),
            ])->save();
            if ($coupon) {
                $coupon->increment('used_count');
                CouponRedemption::create(['coupon_id' => $coupon->id, 'customer_id' => $customer?->id, 'order_id' => $locked->id, 'coupon_code' => $coupon->code, 'order_subtotal' => $subtotal, 'discount_amount' => $discount]);
            }
            return $locked->fresh()->load(['customer', 'items', 'storeGroups', 'paymentMethod', 'shippingMethod', 'coupon']);
        });

        return response()->json(['message' => 'Order updated successfully.', 'order' => $updated]);
    }

    public function destroyAdmin(Request $request, Order $order): JsonResponse
    {
        if (!$request->user() instanceof Admin) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (in_array($order->status, ['pending', 'confirmed'], true)) {
            $order = $this->cancelOrder($order, $request->user());
            $this->notifications->cancelled($order);
        }

        $order->delete();

        return response()->json(['message' => 'Order removed from the admin console.']);
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
        $isAdminOrder = $request->user() instanceof Admin || $request->attributes->has('order_actor');
        $actor = $request->attributes->get('order_actor') ?? $customer;
        $isGuestOrder = $isAdminOrder ? (bool) $request->attributes->get('admin_guest', false) : false;

        if (!$isAdminOrder && !$customer instanceof Customer) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate($this->checkoutRules() + [
            'shipping_name' => ['required', 'string', 'max:255'],
            'shipping_phone' => ['required', 'string', 'max:32'],
            'shipping_email' => ['nullable', 'email', 'max:255'],
            'shipping_address' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
            'internal_note' => [$isAdminOrder ? 'nullable' : 'prohibited', 'string', 'max:5000'],
            'paid_amount' => [$isAdminOrder ? 'nullable' : 'prohibited', 'numeric', 'min:0'],
            'manual_discount_kind' => [$isAdminOrder ? 'nullable' : 'prohibited', 'in:flat,percent'],
            'manual_discount_value' => [$isAdminOrder ? 'nullable' : 'prohibited', 'numeric', 'min:0'],
            'exchange_for' => [$isAdminOrder ? 'nullable' : 'prohibited', 'integer', 'exists:return_requests,id'],
            // Cloudflare Turnstile token - required once TURNSTILE_SECRET_KEY is set.
            'turnstile_token' => ['nullable', 'string', 'max:2048'],
        ]);

        if (!$isAdminOrder && ($data['shipping_email'] ?? null) !== null) {
            throw ValidationException::withMessages(['shipping_email' => ['Only administrators may set an order contact email.']]);
        }

        if (!$isAdminOrder) {
            $this->turnstile->assertHuman($request);
        }

        $exchangeReturn = null;
        if (!empty($data['exchange_for'])) {
            $exchangeReturn = ReturnRequest::query()->with(['order:id,customer_id', 'exchangeOrder:id,status'])->findOrFail($data['exchange_for']);
            if ($exchangeReturn->type !== 'exchange' || !in_array($exchangeReturn->status, ['requested', 'approved', 'in_transit', 'received'], true) || ($exchangeReturn->exchange_order_id && $exchangeReturn->exchangeOrder?->status !== 'cancelled')) {
                throw ValidationException::withMessages(['exchange_for' => ['This exchange is not ready for a replacement order.']]);
            }
            if ((int) $exchangeReturn->customer_id !== (int) ($customer?->id)) {
                throw ValidationException::withMessages(['customer_id' => ['The replacement customer must match the return request.']]);
            }
            if (!empty($data['coupon_code'])) {
                throw ValidationException::withMessages(['coupon_code' => ['Exchange orders cannot use coupons.']]);
            }
        }

        // Uses the same validation, availability, and pricing rules as checkout preview.
        $data['ip_address'] = $request->ip();
        $preview = $this->checkout->preview($customer, $data);

        $order = DB::transaction(function () use ($customer, $actor, $isGuestOrder, $data, $preview, $exchangeReturn) {
            $paymentMethod = PaymentMethod::query()->active()->find($data['payment_method_id']);
            if (!$paymentMethod) {
                throw ValidationException::withMessages([
                    'payment_method_id' => ['Selected payment method is unavailable.'],
                ]);
            }

            $shippingMethod = ShippingMethod::query()->whereNull('seller_id')->where('is_store_option',false)->active()->find($data['shipping_method_id']);
            if (!$shippingMethod) {
                throw ValidationException::withMessages([
                    'shipping_method_id' => ['Selected shipping method is unavailable.'],
                ]);
            }

            $order = Order::create([
                'order_number' => $this->generateOrderNumber(),
                'customer_id' => $customer?->id,
                'reseller_id' => $customer?->reseller_id,
                'payment_method_id' => $paymentMethod->id,
                'shipping_method_id' => $shippingMethod->id,
                'status' => $exchangeReturn ? 'confirmed' : 'pending',
                'payment_status' => $exchangeReturn ? 'paid' : 'unpaid',
                'payment_method_code' => $exchangeReturn ? 'exchange' : $paymentMethod->code,
                'payment_method_name' => $exchangeReturn ? 'Exchange - no charge' : $paymentMethod->name,
                'shipping_method_code' => $exchangeReturn ? 'manual' : $shippingMethod->code,
                'shipping_method_name' => $exchangeReturn ? 'Manual exchange shipment' : $shippingMethod->name,
                'shipping_charge' => 0,
                'shipping_currency' => $shippingMethod->currency,
                'subtotal' => 0,
                'discount_total' => 0,
                'total' => 0,
                'paid_amount' => $data['paid_amount'] ?? null,
                'manual_ship' => (bool) $exchangeReturn,
                'exchange_for' => $exchangeReturn ? (string) $exchangeReturn->id : null,
                'manual_discount_kind' => $data['manual_discount_kind'] ?? null,
                'manual_discount_value' => $data['manual_discount_value'] ?? null,
                'internal_note' => $data['internal_note'] ?? null,
                'shipping_name' => $data['shipping_name'],
                'shipping_phone' => $data['shipping_phone'],
                'shipping_email' => $data['shipping_email'] ?? $customer?->email,
                'is_guest' => $isGuestOrder,
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
                    ->availableForSale()
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

                    $result = $this->inventory->consumeVariation($variation, $quantity, $actor, 'order_sale', [
                        'order_id' => $order->id,
                    ]);
                } else {
                    $result = $this->inventory->consumeProduct($product, $quantity, $actor, 'order_sale', [
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

            $shipping = $this->storeShipping->quote($shippingLines, $shippingMethod, $data['store_shipping_methods'] ?? []);
            $coupon = $preview['coupon'] ? Coupon::query()->lockForUpdate()->find($preview['coupon']->id) : null;
            if ($coupon && ($reason = $coupon->unusableReason())) {
                throw ValidationException::withMessages(['coupon_code' => [$reason]]);
            }
            if ($coupon && $customer && $coupon->per_customer_limit !== null && CouponRedemption::query()->where('coupon_id', $coupon->id)->where('customer_id', $customer->id)->count() >= $coupon->per_customer_limit) {
                throw ValidationException::withMessages(['coupon_code' => ['Coupon usage limit reached for this customer.']]);
            }
            $couponDiscount = $coupon ? (float) $preview['discount_total'] : 0.0;
            $manualDiscount = $exchangeReturn ? $subtotal : ($coupon ? 0.0 : $this->manualDiscountAmount($subtotal, $data['manual_discount_kind'] ?? null, (float) ($data['manual_discount_value'] ?? 0)));
            if ($exchangeReturn) {
                $shipping['total'] = 0;
                $shipping['groups'] = array_map(function (array $group) {
                    $group['shipping_method_id'] = null;
                    $group['shipping_method_code'] = 'exchange';
                    $group['shipping_method_name'] = 'Exchange - no charge';
                    $group['shipping_charge'] = 0;
                    return $group;
                }, $shipping['groups']);
            }
            $discountTotal = round($couponDiscount + $manualDiscount, 2);
            $total = round($subtotal + $shipping['total'] - $discountTotal, 2);

            $order->storeGroups()->createMany(collect($shipping['groups'])->map(function (array $group) {
                unset($group['shipping_options']);
                return $group;
            })->all());

            $order->fill([
                'coupon_id' => $coupon?->id,
                'coupon_code' => $coupon?->code,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'manual_discount_kind' => $data['manual_discount_kind'] ?? null,
                'manual_discount_value' => $data['manual_discount_value'] ?? null,
                'shipping_charge' => $shipping['total'],
                'total' => $total,
            ])->save();

            if ($coupon) {
                $coupon->increment('used_count');

                CouponRedemption::create([
                    'coupon_id' => $coupon->id,
                    'customer_id' => $customer?->id,
                    'order_id' => $order->id,
                    'coupon_code' => $coupon->code,
                    'order_subtotal' => $subtotal,
                    'discount_amount' => $discountTotal,
                ]);
            }

            if ($exchangeReturn) {
                $lockedReturn = ReturnRequest::query()->lockForUpdate()->findOrFail($exchangeReturn->id);
                $linkedOrder = $lockedReturn->exchange_order_id ? Order::query()->lockForUpdate()->find($lockedReturn->exchange_order_id) : null;
                if (!in_array($lockedReturn->status, ['requested', 'approved', 'in_transit', 'received'], true) || ($linkedOrder && $linkedOrder->status !== 'cancelled')) {
                    throw ValidationException::withMessages(['exchange_for' => ['A replacement order has already been created for this exchange.']]);
                }
                $lockedReturn->update(['exchange_order_id' => $order->id]);
            }

            return $order->load(['items', 'storeGroups', 'paymentMethod', 'shippingMethod', 'coupon']);
        });

        $this->notifications->placed($order->load(['customer', 'items.seller']));

        return response()->json([
            'message' => 'Order placed successfully.',
            'order' => $order,
        ], 201);
    }

    /** Create an order from the admin console using the same authoritative checkout path. */
    public function storeAdmin(Request $request): JsonResponse
    {
        if (!$request->user() instanceof Admin) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'guest' => ['sometimes', 'boolean'],
            'shipping_name' => ['required', 'string', 'max:255'],
            'shipping_phone' => ['required', 'string', 'max:32'],
            'shipping_email' => ['nullable', 'email', 'max:255'],
            'shipping_address' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
            'internal_note' => ['nullable', 'string', 'max:5000'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'exchange_for' => ['nullable', 'integer', 'exists:return_requests,id'],
        ] + $this->checkoutRules());

        $customer = !empty($data['customer_id']) ? Customer::findOrFail($data['customer_id']) : null;
        $request->attributes->set('order_actor', $request->user());
        $request->attributes->set('admin_guest', empty($data['customer_id']) || (bool) ($data['guest'] ?? false));
        $request->setUserResolver(fn () => $customer);
        $response = $this->store($request);
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $payload = $response->getData(true);
            $payload['order']['is_guest'] = empty($data['customer_id']) || ($data['guest'] ?? false);
            Order::whereKey($payload['order']['id'])->update(['is_guest' => $payload['order']['is_guest']]);
            $response->setData($payload);
        }
        return $response;
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
            'store_shipping_methods' => ['nullable', 'array'],
            'store_shipping_methods.*' => ['integer'],
            'device_id' => ['nullable', 'string', 'regex:/^[a-f0-9]{16,64}$/i'],
        ];
    }

    private function manualDiscountAmount(float $subtotal, ?string $kind, float $value): float
    {
        if ($value <= 0 || !in_array($kind, ['flat', 'percent'], true)) return 0.0;
        $amount = $kind === 'percent' ? round($subtotal * $value / 100) : round($value);
        return min($subtotal, max(0, $amount));
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
