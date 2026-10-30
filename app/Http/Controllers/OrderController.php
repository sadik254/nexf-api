<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\DeliveryGroup;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\ShippingMethod;
use App\Models\OrderItem;
use App\Models\OrderStoreGroup;
use App\Models\Seller;
use App\Services\InventoryService;
use App\Services\SubOrderService;
use App\Services\CheckoutService;
use App\Services\OrderNotificationService;
use App\Services\SteadfastService;
use App\Services\StoreShippingService;
use App\Services\TurnstileService;
use App\Services\WalletBalanceService;
use App\Services\WalletLedgerService;
use App\Models\CustomerWalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(private InventoryService $inventory, private CheckoutService $checkout, private OrderNotificationService $notifications, private SteadfastService $steadfast, private StoreShippingService $storeShipping, private TurnstileService $turnstile, private SubOrderService $subOrders)
    {
    }

    /**
     * A store sees the sub-orders carrying its lines: its own, or the shared
     * delivery group's parcel its lines ride in.
     */
    private function sellerGroups(int $sellerId): \Closure
    {
        return fn ($query) => $query->where(fn ($q) => $q->where('seller_id', $sellerId)->orWhereHas('items', fn ($items) => $items->where('seller_id', $sellerId)));
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
            'shipping_area' => ['nullable', 'string', 'max:150'],
            'shipping_district' => ['nullable', 'string', 'max:150'],
        ] + $this->checkoutRules());

        $customer = !empty($data['customer_id']) ? Customer::findOrFail($data['customer_id']) : null;
        if (!empty($data['editing_order_id'])) $data['exclude_order_id'] = (int) $data['editing_order_id'];
        unset($data['editing_order_id']);
        $preview = $this->checkout->preview($customer, $data + ['ip_address' => $request->ip(), 'staff_order' => true]);

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

    public function previewGuest(Request $request): JsonResponse
    {
        $data = $request->validate($this->checkoutRules() + [
            'shipping_name' => ['required', 'string', 'max:255'],
            'shipping_phone' => ['required', 'string', 'max:32'],
            'shipping_email' => ['nullable', 'email', 'max:255'],
            'shipping_address' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
        ]);
        $this->assertGuestPhone($data['shipping_phone']);
        $preview = $this->checkout->preview(null, $data + ['ip_address' => $request->ip()]);
        return $this->checkoutPreviewResponse($preview);
    }

    public function storeGuest(Request $request): JsonResponse
    {
        $request->attributes->set('guest_checkout', true);
        return $this->store($request);
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
            'applied_discounts' => collect($preview['applied_discounts'] ?? [])->map(fn (array $discount) => [
                'id' => $discount['coupon']->id,
                'name' => $discount['coupon']->name ?: $discount['coupon']->code,
                'code' => $discount['coupon']->code,
                'kind' => $discount['coupon']->is_automatic ? 'automatic' : 'coupon',
                'amount' => $discount['amount'],
            ])->values(),
            'shipping_groups' => $preview['shipping_groups'],
            'shipping_charge' => $preview['shipping_charge'], 'total' => $preview['total'],
            'wallet_amount' => $preview['wallet_amount'] ?? 0,
            'wallet_reward_amount' => $preview['wallet_reward_amount'] ?? 0,
            'wallet_withdrawable_amount' => $preview['wallet_withdrawable_amount'] ?? 0,
            'payable' => $preview['payable'] ?? $preview['total'],
        ]);
    }

    public function cancelCustomer(Request $request, Order $order): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        if ((int) $order->customer_id !== (int) $customer->id) return response()->json(['message' => 'Forbidden.'], 403);
        if ($order->status !== 'pending') return response()->json(['message' => 'Only pending orders can be cancelled by customers.'], 422);
        $order = $this->cancelOrder($order, $customer, 'customer');
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
            ->latest();

        return response()->json($this->paginateOrderList($query, $request));
    }

    public function showAdmin(Request $request, Order $order): JsonResponse
    {
        if (!$request->user() instanceof Admin) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($this->withCustomerHistory($order->load(['customer', 'items', 'storeGroups', 'paymentMethod', 'shippingMethod', 'coupon'])));
    }

    public function indexSellerOrdersForSuperAdmin(Request $request, Seller $seller): JsonResponse
    {
        if (!$request->user() instanceof Admin || $request->user()->role !== 'super_admin') return response()->json(['message' => 'Forbidden.'], 403);
        return response()->json($this->paginateOrderList(Order::whereHas('items', fn ($q) => $q->where('seller_id', $seller->id))->with(['customer', 'items' => fn ($q) => $q->where('seller_id', $seller->id), 'storeGroups' => $this->sellerGroups($seller->id)])->latest(), $request, $seller->id));
    }

    public function showSellerOrderForSuperAdmin(Request $request, Seller $seller, Order $order): JsonResponse
    {
        if (!$request->user() instanceof Admin || $request->user()->role !== 'super_admin') return response()->json(['message' => 'Forbidden.'], 403);
        if (!$order->items()->where('seller_id', $seller->id)->exists()) return response()->json(['message' => 'Order does not contain items from this seller.'], 404);
        return response()->json($this->withCustomerHistory($order->load(['customer', 'items' => fn ($q) => $q->where('seller_id', $seller->id), 'storeGroups' => $this->sellerGroups($seller->id), 'paymentMethod', 'shippingMethod', 'coupon'])));
    }

    public function cancelSellerOrderForSuperAdmin(Request $request, Seller $seller, Order $order): JsonResponse
    {
        if (!$request->user() instanceof Admin || $request->user()->role !== 'super_admin') return response()->json(['message' => 'Forbidden.'], 403);
        if (!$order->items()->where('seller_id', $seller->id)->exists()) return response()->json(['message' => 'Order does not contain items from this seller.'], 404);
        $order = $this->cancelOrder($order, $request->user(), 'admin');
        $this->notifications->cancelled($order);
        return response()->json(['message' => 'Seller order cancelled and inventory restored successfully.', 'order' => $order]);
    }

    public function indexSeller(Request $request): JsonResponse
    {
        /** @var Seller $seller */
        $seller = $request->user();
        return response()->json($this->paginateOrderList(
            Order::query()
                ->visibleToSeller()
                ->whereHas('items', fn ($query) => $query->where('seller_id', $seller->id))
                ->with([
                    'customer:id,name,email,phone',
                    'items' => fn ($query) => $query->where('seller_id', $seller->id),
                    'storeGroups' => $this->sellerGroups($seller->id),
                    'shippingMethod',
                ])
            ->latest(), $request, $seller->id
        ));
    }

    public function showSeller(Request $request, Order $order): JsonResponse
    {
        /** @var Seller $seller */
        $seller = $request->user();
        abort_unless(! $order->isHeldFromSeller() && $order->items()->where('seller_id', $seller->id)->exists(), 404);

        $view = $this->sellerView($order->load([
            'customer:id,name,email,phone',
            'items' => fn ($query) => $query->where('seller_id', $seller->id),
            'storeGroups' => $this->sellerGroups($seller->id),
            'shippingMethod',
        ])->toArray(), $seller->id);
        $view['customer_history'] = $this->customerHistory($order);
        return response()->json($view);
    }

    /**
     * A store's view of an order: its own lines' value, its delivery charge and
     * discount share instead of the whole basket's, and its own sub-order's
     * status rather than the slowest store's.
     */
    private function sellerView(array $order, int $sellerId): array
    {
        $items = collect($order['items'] ?? []);
        $sellerSubtotal = round($items->sum(fn (array $item) => (float) $item['line_subtotal']), 2);
        $storeGroup = collect($order['store_groups'] ?? [])->first(fn (array $group) => (int) ($group['seller_id'] ?? 0) === $sellerId);
        $shippingCharge = (float) ($storeGroup['shipping_charge'] ?? 0);
        $orderSubtotal = (float) ($order['subtotal'] ?? 0);
        $discountShare = $storeGroup && array_key_exists('discount_share', $storeGroup)
            ? (float) $storeGroup['discount_share']
            : ($orderSubtotal > 0 ? round((float) ($order['discount_total'] ?? 0) * ($sellerSubtotal / $orderSubtotal), 2) : 0);
        $order['seller_subtotal'] = $sellerSubtotal;
        $order['seller_shipping_charge'] = $shippingCharge;
        $order['seller_discount_share'] = $discountShare;
        $order['seller_total'] = round($sellerSubtotal + $shippingCharge - $discountShare, 2);
        if ($order['status'] !== 'cancelled' && $items->isNotEmpty()) {
            $rank = ['pending' => 0, 'confirmed' => 1, 'packed' => 2, 'shipped' => 3, 'delivered' => 4, 'return_pending' => 5, 'returned' => 6];
            $live = $items->pluck('fulfillment_status')->reject(fn ($s) => $s === 'cancelled');
            if ($live->isNotEmpty()) $order['status'] = $live->sortBy(fn ($s) => $rank[$s] ?? 0)->first();
        }
        return $order;
    }

    private function withCustomerHistory(Order $order): Order
    {
        $order->setAttribute('customer_history', $this->customerHistory($order));
        return $order;
    }

    /** Platform-wide counts only; no other order data is disclosed to a store. */
    private function customerHistory(Order $order): array
    {
        $phone = $this->normalizedHistoryPhone($order->shipping_phone);
        $orders = Order::query()->whereNull('exchange_for')
            ->where(function ($query) use ($order, $phone): void {
                if ($order->customer_id) $query->orWhere('customer_id', $order->customer_id);
                if ($phone) {
                    $query->orWhere('shipping_phone', $phone)
                        ->orWhere('shipping_phone', '+88'.$phone)
                        ->orWhere('shipping_phone', '88'.$phone);
                }
            })->get(['status']);
        $received = $orders->whereIn('status', ['delivered', 'completed'])->count();
        $failed = $orders->whereIn('status', ['cancelled', 'refused'])->count();
        return ['orders' => $orders->count(), 'received' => $received, 'failed' => $failed, 'pending' => $orders->count() - $received - $failed];
    }

    private function normalizedHistoryPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (str_starts_with($digits, '880')) $digits = '0'.substr($digits, 3);
        return preg_match('/^01[3-9]\d{8}$/', $digits) ? $digits : null;
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        if (!$request->user() instanceof Admin) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $confirmsPayment = $request->has('paid_amount') || $request->has('payment_sender') || $request->has('payment_reference');
        if ($confirmsPayment
            && ! $request->user()->hasPermission('payments.confirm')) {
            return response()->json(['message' => "You don't have access to this.", 'permission' => 'payments.confirm'], 403);
        }
        if ($confirmsPayment) $request->attributes->set('audit_permission', 'payments.confirm');
        if ($request->exists('items') || $request->exists('payment_method_id') || $request->exists('shipping_method_id') || $request->exists('coupon_code') || $request->exists('customer_id')) {
            return $this->replacePendingAdminOrder($request, $order);
        }
        $data = $request->validate([
            'paid_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'internal_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_guest' => ['sometimes', 'boolean'],
            'manual_ship' => ['sometimes', 'boolean'],
            'exchange_for' => ['sometimes', 'nullable', 'string', 'max:80'],
            'status' => ['sometimes', 'in:pending'],
            'packed' => ['sometimes', 'boolean'],
            'shipping_name' => ['sometimes', 'string', 'max:255'],
            'shipping_phone' => ['sometimes', 'string', 'max:32'],
            'shipping_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'shipping_address' => ['sometimes', 'string'],
            'shipping_area' => ['sometimes', 'nullable', 'string', 'max:150'],
            'shipping_district' => ['sometimes', 'nullable', 'string', 'max:150'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'payment_sender' => ['sometimes', 'nullable', 'string', 'max:40'],
            'payment_reference' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);
        if (array_key_exists('status', $data)) {
            if (!empty($data['packed'])) {
                throw ValidationException::withMessages(['status' => ['A processing order cannot be packed.']]);
            }
            unset($data['status']);
            DB::transaction(function () use ($order): void {
                $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
                $items = $locked->items()->lockForUpdate()->get();
                $liveItems = $items->reject(fn (OrderItem $item) => $item->fulfillment_status === 'cancelled');
                if ($liveItems->isEmpty() || $liveItems->every(fn (OrderItem $item) => $item->fulfillment_status === 'pending')) return;
                if ($liveItems->contains(fn (OrderItem $item) =>
                    !in_array($item->fulfillment_status, ['pending', 'confirmed', 'packed'], true)
                    || $item->courier_consignment_id !== null
                    || $item->shipped_at !== null
                    || $item->delivered_at !== null
                )) {
                    throw ValidationException::withMessages(['status' => ['Only unshipped pending, confirmed, or packed lines can return to processing.']]);
                }

                $locked->items()->where('fulfillment_status', '!=', 'cancelled')->update(['fulfillment_status' => 'pending', 'confirmed_at' => null]);
                $locked->update(['packed_at' => null]);
                $this->refreshOrderStatus($locked);
            });
        }
        if (array_key_exists('packed', $data)) {
            $packed = (bool) $data['packed'];
            unset($data['packed']);
            DB::transaction(function () use ($order, $packed): void {
                $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
                if ($locked->storeGroups()->exists() && $locked->items()->whereNull('store_group_id')->exists()) $this->subOrders->sync($locked);
                // Only NEXF's own parcels: third-party stores pack and ship theirs.
                $groups = $locked->storeGroups()->where('ships_by', 'nexf')->lockForUpdate()->get()
                    ->each(fn (OrderStoreGroup $group) => $group->setRelation('items', $group->items()->where('fulfillment_status', '!=', 'cancelled')->lockForUpdate()->get()))
                    ->filter(fn (OrderStoreGroup $group) => $group->items->isNotEmpty())->values();
                if ($packed) {
                    if ($groups->isEmpty() || $groups->contains(fn (OrderStoreGroup $group) => $group->items->contains(fn (OrderItem $item) => $item->fulfillment_status !== 'confirmed'))) {
                        throw ValidationException::withMessages(['packed' => ['Every NEXF parcel on this order must be confirmed before it can be packed.']]);
                    }
                    // A manual-ship parcel (an exchange) is booked by hand; the rest go to Steadfast.
                    if ($locked->manual_ship) {
                        foreach ($groups as $group) $group->items()->where('fulfillment_status', 'confirmed')->update(['fulfillment_status' => 'packed']);
                    } else {
                        $this->bookGroups($groups);
                    }
                } elseif ($groups->isNotEmpty() && $groups->every(fn (OrderStoreGroup $group) => $group->items->every(fn (OrderItem $item) => $item->fulfillment_status === 'packed'))) {
                    foreach ($groups as $group) $group->items()->where('fulfillment_status', 'packed')->update(['fulfillment_status' => 'confirmed']);
                } else {
                    throw ValidationException::withMessages(['packed' => ['Only a fully packed, unshipped order can be unpacked.']]);
                }
                $this->refreshOrderStatus($locked);
            });
        }
        $wasHeldFromSeller = $order->isHeldFromSeller();
        $order->update($data);
        $freshOrder = $order->fresh();
        if ($wasHeldFromSeller && ! $freshOrder->isHeldFromSeller()) {
            $this->notifications->manualPaymentReceived($freshOrder->load(['items.seller']));
        }
        return response()->json([
            'message' => 'Order details updated successfully. Fulfilment status is derived from item fulfilment.',
            'order' => $freshOrder->load(['customer', 'items', 'storeGroups', 'paymentMethod', 'shippingMethod', 'coupon']),
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
            'shipping_area' => ['nullable', 'string', 'max:150'],
            'shipping_district' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string'],
            'internal_note' => ['nullable', 'string', 'max:5000'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'manual_discount_kind' => ['nullable', 'in:flat,percent'],
            'manual_discount_value' => ['nullable', 'numeric', 'min:0'],
        ] + $this->checkoutRules());

        $customer = !empty($data['customer_id']) ? Customer::findOrFail($data['customer_id']) : null;
        $data['ip_address'] = $request->ip();
        $data['exclude_order_id'] = $order->id;
        $actor = $request->attributes->get('seller_staff') ?? $request->user();
        $data['staff_order'] = true;

        $updated = DB::transaction(function () use ($order, $data, $customer, $actor) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            // NEXF edits only the parcels it ships. Parcels stores ship
            // themselves keep their lines, state, courier details, discount and
            // coupon exactly as they are, shipped or not.
            $storeParcels = $locked->storeGroups()->where('ships_by', 'seller')->with('items')->get();
            $storeSellerIds = $storeParcels->flatMap->items->pluck('seller_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
            $storeGroupIds = $storeParcels->pluck('id');
            $nexfItems = $locked->items()->get()->reject(fn (OrderItem $item) => $storeGroupIds->contains($item->store_group_id))->values();
            $sellerOf = Product::query()->whereIn('id', collect($data['items'])->pluck('product_id'))->pluck('seller_id', 'id');
            [$storeData, $nexfData] = collect($data['items'])->partition(fn (array $line) => in_array((int) $sellerOf->get($line['product_id']), $storeSellerIds, true));
            if ($this->lineKeys($storeData) !== $this->lineKeys($storeParcels->flatMap->items)) {
                throw ValidationException::withMessages(['items' => ["A store's parcel can only be changed by that store."]]);
            }

            $fulfillmentStates = $nexfItems->pluck('fulfillment_status')->unique()->values();
            if (!in_array($locked->status, ['pending', 'confirmed'], true) || $fulfillmentStates->count() > 1 || ($fulfillmentStates->isNotEmpty() && !in_array($fulfillmentStates->first(), ['pending', 'confirmed'], true))) {
                throw ValidationException::withMessages(['order' => ["Only a pending or fully confirmed NEXF parcel can be edited before shipping."]]);
            }
            $itemFulfillmentState = $fulfillmentStates->first() ?? 'pending';
            if ($nexfItems->contains(fn (OrderItem $item) => $item->review()->exists()) || DB::table('return_requests')->whereIn('order_item_id', $nexfItems->pluck('id'))->exists()) {
                throw ValidationException::withMessages(['order' => ['This order has review or return history and cannot have its lines replaced.']]);
            }

            foreach ($nexfItems as $oldItem) {
                $this->inventory->restoreOrderItem($oldItem, $actor, 'admin_order_edit_restore');
            }

            // A store's own coupon stays with the store's parcel; the rest are requoted on NEXF's lines.
            $redemptions = CouponRedemption::query()->where('order_id', $locked->id)->lockForUpdate()->get();
            $couponSellers = Coupon::query()->whereIn('id', $redemptions->pluck('coupon_id')->filter())->pluck('seller_id', 'id');
            [$keptRedemptions, $oldRedemptions] = $redemptions->partition(fn (CouponRedemption $r) => in_array((int) $couponSellers->get($r->coupon_id), $storeSellerIds, true));

            $keepRequest = $locked->stock_request && $itemFulfillmentState === 'pending';
            $data['stock_request'] = $keepRequest;
            // Requote only after the old lot allocations are restored, so unchanged quantities remain available.
            $quoteData = ['items' => $nexfData->values()->all()] + $data;
            if ($keptRedemptions->contains(fn (CouponRedemption $r) => strcasecmp((string) $r->coupon_code, (string) ($data['coupon_code'] ?? '')) === 0)) unset($quoteData['coupon_code']);
            // With no NEXF lines left there's nothing to quote: the stores' parcels carry the order.
            $preview = $nexfData->isEmpty()
                ? ['shippingMethod' => ShippingMethod::query()->findOrFail($data['shipping_method_id']), 'applied_discounts' => []]
                : $this->checkout->preview($customer, $quoteData);

            $oldRedemptions->each(function (CouponRedemption $oldRedemption): void {
                if ($oldRedemption->coupon_id) Coupon::query()->whereKey($oldRedemption->coupon_id)->decrement('used_count');
                $oldRedemption->delete();
            });
            OrderItem::query()->whereIn('id', $nexfItems->pluck('id'))->delete();
            $locked->storeGroups()->whereNotIn('id', $storeParcels->pluck('id'))->delete();

            $shippingLines = [];
            foreach ($nexfData as $itemData) {
                [$product, $lineSubtotal] = $this->addEditedLine($locked, $itemData, $actor, $itemFulfillmentState, $keepRequest);
                $shippingLines[] = ['product' => $product, 'subtotal' => $lineSubtotal];
            }

            $paymentMethod = PaymentMethod::query()->active()->findOrFail($data['payment_method_id']);
            $shippingMethod = $preview['shippingMethod'];
            $shipping = $this->storeShipping->quote($shippingLines, $shippingMethod, $data['store_shipping_methods'] ?? [], $data['store_delivery_options'] ?? []);
            $appliedDiscounts = $this->lockAppliedDiscounts($preview, $customer, $data['shipping_phone']);
            $coupon = $appliedDiscounts[0]['coupon'] ?? null;
            $couponDiscount = round(array_sum(array_column($appliedDiscounts, 'amount')) + $keptRedemptions->sum(fn (CouponRedemption $r) => (float) $r->discount_amount), 2);
            $locked->storeGroups()->createMany(collect($shipping['groups'])->map(function (array $group) {
                unset($group['shipping_options']);
                return $group;
            })->all());

            $this->subOrders->link($locked);
            $groups = $locked->storeGroups()->with('items')->get();
            if ($groups->where('ships_by', 'seller')->count() !== $storeParcels->count()) {
                throw ValidationException::withMessages(['items' => ['NEXF can only add products it ships.']]);
            }
            $subtotal = round((float) $groups->flatMap->items->sum(fn (OrderItem $item) => (float) $item->line_subtotal), 2);
            $shippingTotal = round((float) $groups->sum(fn (OrderStoreGroup $g) => (float) $g->shipping_charge), 2);
            $storeDiscount = round((float) $storeParcels->sum(fn (OrderStoreGroup $g) => (float) $g->manual_discount), 2);
            $nexfSubtotal = round((float) $groups->where('ships_by', 'nexf')->flatMap->items->sum(fn (OrderItem $item) => (float) $item->line_subtotal), 2);
            $manualDiscount = $coupon ? 0.0 : $this->manualDiscountAmount($nexfSubtotal, $data['manual_discount_kind'] ?? null, (float) ($data['manual_discount_value'] ?? 0));
            $discount = round($couponDiscount + $manualDiscount + $storeDiscount, 2);
            $keptCoupon = $coupon ? null : $keptRedemptions->first();
            $locked->fill([
                'customer_id' => $customer?->id, 'reseller_id' => $customer?->reseller_id,
                'is_guest' => empty($data['customer_id']) || (bool) ($data['guest'] ?? false),
                'payment_method_id' => $paymentMethod->id, 'payment_method_code' => $paymentMethod->code, 'payment_method_name' => $paymentMethod->name,
                'shipping_method_id' => $shippingMethod->id, 'shipping_method_code' => $shippingMethod->code, 'shipping_method_name' => $shippingMethod->name,
                'shipping_currency' => $shippingMethod->currency, 'shipping_name' => $data['shipping_name'], 'shipping_phone' => $data['shipping_phone'],
                'shipping_email' => $data['shipping_email'] ?? $customer?->email, 'shipping_address' => $data['shipping_address'],
                'shipping_area' => $data['shipping_area'] ?? null, 'shipping_district' => $data['shipping_district'] ?? null,
                'notes' => $data['notes'] ?? null, 'internal_note' => $data['internal_note'] ?? null, 'paid_amount' => $data['paid_amount'] ?? null,
                'coupon_id' => $coupon?->id ?? $keptCoupon?->coupon_id, 'coupon_code' => $coupon?->code ?? $keptCoupon?->coupon_code, 'manual_discount_kind' => $data['manual_discount_kind'] ?? null,
                'manual_discount_value' => $data['manual_discount_value'] ?? null, 'subtotal' => $subtotal,
                'discount_total' => $discount, 'shipping_charge' => $shippingTotal, 'total' => round($subtotal + $shippingTotal - $discount, 2),
            ])->save();
            foreach ($appliedDiscounts as $appliedDiscount) {
                $appliedCoupon = $appliedDiscount['coupon'];
                $appliedCoupon->increment('used_count');
                CouponRedemption::create(['coupon_id' => $appliedCoupon->id, 'customer_id' => $customer?->id, 'order_id' => $locked->id, 'coupon_code' => $appliedCoupon->code, 'coupon_name' => $appliedCoupon->name, 'is_automatic' => $appliedCoupon->is_automatic, 'order_subtotal' => $subtotal, 'discount_amount' => $appliedDiscount['amount']]);
            }
            $this->subOrders->sync($locked);
            $this->refreshOrderStatus($locked);
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
            $order = $this->cancelOrder($order, $request->user(), 'admin');
            $this->notifications->cancelled($order);
        }

        $order->delete();

        return response()->json(['message' => 'Order removed from the admin console.']);
    }

    public function fulfillSellerItem(Request $request, Order $order, OrderItem $item): JsonResponse
    {
        /** @var Seller $seller */
        $seller = $request->user();
        abort_if($order->isHeldFromSeller(), 404);
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
        if ((int) $item->order_id !== (int) $order->id) {
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
            if ($lockedItem->storeGroup) $this->subOrders->refreshStatus($lockedItem->storeGroup);
            if (!$lockedOrder->items()->whereNotIn('fulfillment_status', ['returned', 'cancelled'])->exists()) {
                $lockedOrder->update(['status' => 'cancelled', 'cancelled_at' => $lockedOrder->cancelled_at ?? now(), 'cancelled_by' => $lockedOrder->cancelled_by ?? 'admin']);
            }
            return $lockedOrder->load(['customer', 'items', 'storeGroups', 'paymentMethod', 'shippingMethod', 'coupon']);
        });
        return response()->json(['message' => 'Returned item reconciled and inventory restored.', 'order' => $result]);
    }

    /**
     * Books packed NEXF parcels with Steadfast in one request. Takes store
     * group ids, or (older callers) line ids, which stand for their parcels.
     */
    public function bulkShipAdmin(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof Admin) return response()->json(['message' => 'Forbidden.'], 403);
        $data = $request->validate([
            'store_group_ids' => ['required_without:order_item_ids', 'array', 'min:1', 'max:500'],
            'store_group_ids.*' => ['integer', 'distinct', 'exists:order_store_groups,id'],
            'order_item_ids' => ['required_without:store_group_ids', 'array', 'min:1', 'max:500'],
            'order_item_ids.*' => ['integer', 'distinct', 'exists:order_items,id'],
        ]);
        $groupIds = collect($data['store_group_ids'] ?? []);
        if (!empty($data['order_item_ids'])) {
            $items = OrderItem::query()->whereIn('id', $data['order_item_ids'])->get();
            foreach ($items->whereNull('store_group_id')->pluck('order_id')->unique() as $orderId) $this->subOrders->sync(Order::findOrFail($orderId));
            $groupIds = $groupIds->merge(OrderItem::query()->whereIn('id', $data['order_item_ids'])->pluck('store_group_id'))->filter()->unique();
        }
        $groups = OrderStoreGroup::query()->with(['order.customer'])->whereIn('id', $groupIds)->get()
            ->each(fn (OrderStoreGroup $group) => $group->setRelation('items', $group->items()->where('fulfillment_status', '!=', 'cancelled')->get()));

        if ($groups->contains(fn (OrderStoreGroup $group) => $group->ships_by !== 'nexf')) {
            return response()->json(['message' => 'A store ships its own parcels; only NEXF parcels can be booked here.'], 422);
        }
        if ($groups->contains(fn (OrderStoreGroup $group) => $group->courier_consignment_id || $group->items->isEmpty() || $group->items->contains(fn (OrderItem $item) => $item->fulfillment_status !== 'packed'))) {
            return response()->json(['message' => 'All selected parcels must be packed and not already shipped.'], 422);
        }

        [$successful, $failed] = DB::transaction(fn () => $this->bookGroups($groups));
        $itemIds = fn (array $ids) => OrderItem::query()->whereIn('store_group_id', $ids)->where('fulfillment_status', '!=', 'cancelled')->pluck('id')->values();

        return response()->json([
            'message' => 'Bulk fulfilment processed.',
            'successful_store_group_ids' => $successful,
            'failed_store_group_ids' => $failed,
            'successful_item_ids' => $itemIds($successful),
            'failed_item_ids' => $itemIds($failed),
        ]);
    }

    /**
     * Packs NEXF parcels and books them with Steadfast in one bulk request. A
     * parcel the courier accepted moves to Shipped; one it refused stays Packed
     * with the courier's reason, ready to retry.
     *
     * @return array{0: int[], 1: int[]} booked and failed group ids
     */
    private function bookGroups($groups): array
    {
        foreach ($groups as $group) {
            $group->items()->where('fulfillment_status', 'confirmed')->update(['fulfillment_status' => 'packed']);
            $group->load(['order.customer', 'items']);
        }
        $byInvoice = collect($this->steadfast->createBulkConsignments($groups))->keyBy('invoice');
        $successful = [];
        $failed = [];
        foreach ($groups as $group) {
            $result = $byInvoice->get($group->number);
            if (!$result || ($result['status'] ?? null) === 'error' || empty($result['tracking_code'])) {
                $group->update(['courier_error' => $result['message'] ?? $group->fresh()->courier_error ?? 'SteadFast did not create this consignment.']);
                $this->subOrders->refreshStatus($group);
                $failed[] = $group->id;
                continue;
            }
            $this->markGroupShipped($group, [
                'courier_provider' => 'steadfast',
                'tracking_number' => (string) $result['tracking_code'],
                'courier_tracking_url' => $result['tracking_link'] ?? $result['tracking_url'] ?? null,
                'courier_consignment_id' => (string) ($result['consignment_id'] ?? ''),
                'courier_invoice' => $result['invoice'] ?? $group->number,
                'courier_status' => $result['status'] ?? 'in_review',
            ]);
            $this->refreshOrderStatus($group->order);
            $successful[] = $group->id;
        }
        return [$successful, $failed];
    }

    /** Every live line in the parcel moves to Shipped with the parcel's courier details. */
    private function markGroupShipped(OrderStoreGroup $group, array $courier): void
    {
        $courier += ['courier_error' => null];
        $group->update($courier + ['shipped_at' => $group->shipped_at ?? now()]);
        $group->items()->whereIn('fulfillment_status', ['packed', 'confirmed'])->update($courier + [
            'fulfillment_status' => 'shipped',
            'shipped_at' => now(),
            'courier_created_at' => now(),
            'courier_updated_at' => now(),
        ]);
        $this->subOrders->refreshStatus($group->fresh());
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();
        if (!$admin instanceof Admin) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $order = $this->cancelOrder($order, $admin, 'admin');
        $this->notifications->cancelled($order);

        return response()->json([
            'message' => 'Order cancelled and inventory restored successfully.',
            'order' => $order,
        ]);
    }

    public function cancelOrder(Order $order, $actor, string $cancelledBy = 'admin', array $extra = []): Order
    {
        return DB::transaction(function () use ($order, $actor, $cancelledBy, $extra) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($lockedOrder->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'order' => ['Order has already been cancelled.'],
                ]);
            }

            $this->cancelLines($lockedOrder->items, $actor);
            foreach ($lockedOrder->storeGroups()->get() as $group) $this->subOrders->refreshStatus($group);

            $lockedOrder->update(array_merge([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $cancelledBy,
            ], $extra));
            app(WalletLedgerService::class)->cancellationCredit($lockedOrder);

            return $lockedOrder->load(['customer', 'items', 'storeGroups', 'paymentMethod', 'shippingMethod', 'coupon']);
        });
    }

    /**
     * Cancels lines, including parcels already handed to a courier. Shipped
     * lines wait for the courier to bring them back; their stock is restored
     * only when that return is reconciled. Delivered lines must use Returns.
     */
    private function cancelLines($items, $actor): void
    {
        if ($items->contains(fn (OrderItem $item) => $item->fulfillment_status === 'delivered')) {
            throw ValidationException::withMessages([
                'order' => ['Delivered items go back through a return, not a cancel.'],
            ]);
        }

        foreach ($items as $item) {
            if (in_array($item->fulfillment_status, ['cancelled', 'returned', 'return_pending'], true)) continue;
            if ($item->fulfillment_status === 'shipped') {
                $item->update(['fulfillment_status' => 'return_pending']);
                continue;
            }

            $this->inventory->restoreOrderItem($item, $actor);
            $item->update(['fulfillment_status' => 'cancelled']);
        }
    }

    /** A store cancels its own parcel; the order closes when no live lines remain. */
    public function cancelSellerGroup(Request $request, Order $order, OrderStoreGroup $group): JsonResponse
    {
        /** @var Seller $seller */
        $seller = $request->user();
        abort_if($order->isHeldFromSeller(), 404);
        if ((int) $group->order_id !== (int) $order->id || (int) $group->seller_id !== (int) $seller->id || $group->ships_by !== 'seller') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $actor = $request->attributes->get('seller_staff') ?? $seller;
        $closed = false;
        $order = DB::transaction(function () use ($order, $group, $seller, $actor, &$closed) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($lockedOrder->status === 'cancelled') {
                throw ValidationException::withMessages(['order' => ['Order has already been cancelled.']]);
            }

            $parcel = OrderStoreGroup::query()->lockForUpdate()->findOrFail($group->id);
            $items = $parcel->items()->where('seller_id', $seller->id)->lockForUpdate()->get();
            $this->cancelLines($items, $actor);
            $this->subOrders->refreshStatus($parcel->fresh());

            if (! $lockedOrder->items()->whereNotIn('fulfillment_status', ['cancelled', 'returned', 'return_pending'])->exists()) {
                $lockedOrder->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => 'seller']);
                app(WalletLedgerService::class)->cancellationCredit($lockedOrder);
                $closed = true;
            } else {
                app(WalletLedgerService::class)->parcelCancellationCredit($lockedOrder, $parcel->fresh());
                $this->refreshOrderStatus($lockedOrder);
            }

            return $lockedOrder;
        });

        if ($closed) $this->notifications->cancelled($order);

        return response()->json([
            'message' => 'Parcel cancelled.',
            'order' => $this->sellerView($order->load([
                'customer:id,name,email,phone',
                'items' => fn ($query) => $query->where('seller_id', $seller->id),
                'storeGroups' => $this->sellerGroups($seller->id),
                'shippingMethod',
            ])->toArray(), $seller->id),
        ]);
    }

    /** A store may replace only its own unshipped parcel and fund its discount. */
    public function updateSellerGroup(Request $request, Order $order, OrderStoreGroup $group): JsonResponse
    {
        /** @var Seller $seller */
        $seller = $request->user();
        abort_if($order->isHeldFromSeller(), 404);
        if ((int) $group->order_id !== (int) $order->id || (int) $group->seller_id !== (int) $seller->id || $group->ships_by !== 'seller') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.variation_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'manual_discount_kind' => ['nullable', 'in:flat,percent'],
            'manual_discount_value' => ['nullable', 'numeric', 'min:0'],
        ]);
        $actor = $request->attributes->get('seller_staff') ?? $seller;

        $updated = DB::transaction(function () use ($order, $group, $seller, $data, $actor) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $parcel = OrderStoreGroup::query()->lockForUpdate()->findOrFail($group->id);
            $items = $parcel->items()->lockForUpdate()->get();
            $states = $items->pluck('fulfillment_status')->unique();
            if (!in_array($locked->status, ['pending', 'confirmed'], true) || $parcel->courier_consignment_id !== null || $parcel->shipped_at !== null
                || $states->count() > 1 || ($states->isNotEmpty() && !in_array($states->first(), ['pending', 'confirmed'], true))) {
                throw ValidationException::withMessages(['order' => ['Only a pending or confirmed parcel can be edited before shipping.']]);
            }
            if ($items->contains(fn (OrderItem $item) => $item->review()->exists()) || DB::table('return_requests')->whereIn('order_item_id', $items->pluck('id'))->exists()) {
                throw ValidationException::withMessages(['order' => ['This parcel has review or return history and cannot have its lines replaced.']]);
            }
            $state = $states->first() ?? 'pending';
            $deliveryGroups = DeliveryGroup::query()->where('ships_from_seller_id', $seller->id)->get();
            $sellerIds = $deliveryGroups
                ->flatMap(fn (DeliveryGroup $deliveryGroup) => array_map('intval', $deliveryGroup->seller_ids ?? []))
                ->push($seller->id)->unique()->values()->all();
            if ($deliveryGroups->contains('includes_house', true)) $sellerIds[] = null;

            foreach ($items as $oldItem) {
                $this->inventory->restoreOrderItem($oldItem, $actor, 'store_order_edit_restore');
                $oldItem->delete();
            }
            $subtotal = 0.0;
            foreach ($data['items'] as $itemData) {
                [, $lineSubtotal] = $this->addEditedLine($locked, $itemData, $actor, $state, false, $sellerIds, ['store_group_id' => $parcel->id]);
                $subtotal = round($subtotal + $lineSubtotal, 2);
            }
            $discount = $this->manualDiscountAmount($subtotal, $data['manual_discount_kind'] ?? null, (float) ($data['manual_discount_value'] ?? 0));
            $discountTotal = round((float) $locked->discount_total - (float) $parcel->manual_discount + $discount, 2);
            $parcel->update([
                'manual_discount_kind' => $discount > 0 ? $data['manual_discount_kind'] : null,
                'manual_discount_value' => $discount > 0 ? $data['manual_discount_value'] : null,
                'manual_discount' => $discount,
            ]);
            $orderSubtotal = round((float) $locked->items()->sum('line_subtotal'), 2);
            $locked->update([
                'subtotal' => $orderSubtotal,
                'discount_total' => $discountTotal,
                'total' => round($orderSubtotal + (float) $locked->shipping_charge - $discountTotal, 2),
            ]);
            $this->subOrders->sync($locked);
            $this->refreshOrderStatus($locked);
            return $locked;
        });

        return response()->json([
            'message' => 'Parcel updated.',
            'order' => $this->sellerView($updated->fresh()->load([
                'customer:id,name,email,phone',
                'items' => fn ($query) => $query->where('seller_id', $seller->id),
                'storeGroups' => $this->sellerGroups($seller->id),
                'shippingMethod',
            ])->toArray(), $seller->id),
        ]);
    }

    public function fulfillSellerGroup(Request $request, Order $order, OrderStoreGroup $group): JsonResponse
    {
        /** @var Seller $seller */
        $seller = $request->user();
        abort_if($order->isHeldFromSeller(), 404);
        if ((int) $group->order_id !== (int) $order->id || (int) $group->seller_id !== (int) $seller->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        return $this->fulfill($request, $order, $group, null, $seller->id);
    }

    public function fulfillAdminGroup(Request $request, Order $order, OrderStoreGroup $group): JsonResponse
    {
        if (!$request->user() instanceof Admin) return response()->json(['message' => 'Forbidden.'], 403);
        if ((int) $group->order_id !== (int) $order->id) return response()->json(['message' => 'This parcel does not belong to this order.'], 422);
        return $this->fulfill($request, $order, $group);
    }

    /**
     * The older per-line route. Confirming still confirms just that line; every
     * later step moves the whole parcel the line ships in, since a parcel is
     * packed, shipped and delivered as one.
     */
    private function fulfillItem(Request $request, Order $order, OrderItem $item, ?int $sellerId = null): JsonResponse
    {
        if (!$item->store_group_id && $order->storeGroups()->exists()) $this->subOrders->sync($order);
        $group = $item->fresh()->storeGroup;
        if (!$group) throw ValidationException::withMessages(['order' => ['This order has no parcel to fulfil.']]);
        if ($sellerId !== null && $request->input('status') !== 'confirmed' && (int) $group->seller_id !== $sellerId) {
            throw ValidationException::withMessages(['status' => ["These lines ship in {$group->store_name}'s parcel, so that store packs and ships them."]]);
        }
        return $this->fulfill($request, $order, $group, $item, $sellerId);
    }

    /**
     * Moves one parcel a step: confirmed → packed → shipped → delivered.
     *
     * NEXF's parcels (`ships_by` nexf) are booked with Steadfast on Packed and
     * go straight to Shipped. A store's parcel is packed, shipped and marked
     * delivered by the store (or staff on its behalf) with its own courier's
     * details - NEXF never books it.
     */
    private function fulfill(Request $request, Order $order, OrderStoreGroup $group, ?OrderItem $only = null, ?int $sellerId = null): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:pending,confirmed,packed,shipped,delivered'],
            'tracking_number' => ['nullable', 'string', 'max:255'],
            'courier_provider' => ['nullable', 'string', 'max:255'],
            'tracking_url' => ['nullable', 'url', 'max:1000'],
        ]);

        $actor = $request->attributes->get('seller_staff') ?? $request->user();
        $order = DB::transaction(function () use ($order, $group, $only, $data, $actor, $sellerId) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($lockedOrder->status === 'cancelled') {
                throw ValidationException::withMessages(['order' => ['Cancelled orders cannot be fulfilled.']]);
            }
            $lockedGroup = OrderStoreGroup::query()->lockForUpdate()->findOrFail($group->id);
            $items = $lockedGroup->items()->where('fulfillment_status', '!=', 'cancelled')->lockForUpdate()->get();
            // A step back (packed → confirmed, or back to pending) while nothing is booked or shipped.
            $ladder = ['pending', 'confirmed', 'packed', 'shipped', 'delivered'];
            $target = array_search($data['status'], $ladder, true);
            $rank = fn (OrderItem $item) => array_search($item->fulfillment_status, $ladder, true);
            $back = $only ? $items->where('id', $only->id)->values() : $items;
            if ($back->isNotEmpty() && $back->every(fn (OrderItem $item) => $rank($item) !== false && $rank($item) >= $target) && $back->contains(fn (OrderItem $item) => $rank($item) > $target)) {
                if ($lockedGroup->courier_consignment_id || $lockedGroup->shipped_at || $back->contains(fn (OrderItem $item) =>
                    in_array($item->fulfillment_status, ['shipped', 'delivered'], true) || $item->courier_consignment_id !== null || $item->shipped_at !== null
                )) {
                    throw ValidationException::withMessages(['status' => ['A parcel that is booked or shipped cannot go back.']]);
                }
                foreach ($back->filter(fn (OrderItem $item) => $rank($item) > $target) as $item) {
                    $item->update(['fulfillment_status' => $data['status']] + ($target === 0 ? ['confirmed_at' => null] : []));
                }
                if ($target < 2) $lockedGroup->update(['packed_at' => null]);
                $this->subOrders->refreshStatus($lockedGroup->fresh());
                $this->refreshOrderStatus($lockedOrder);
                if ($target < 2 && !$lockedOrder->items()->where('fulfillment_status', 'packed')->exists()) $lockedOrder->update(['packed_at' => null]);
                return $lockedOrder->load(['customer', 'items', 'storeGroups', 'paymentMethod', 'shippingMethod', 'coupon']);
            }
            if ($target === 0) throw ValidationException::withMessages(['status' => ['This parcel is already pending.']]);

            // Each store confirms its own lines, even inside a shared parcel.
            if ($only && $data['status'] === 'confirmed') $items = $items->where('id', $only->id)->values();
            elseif ($sellerId !== null && $data['status'] === 'confirmed') $items = $items->where('seller_id', $sellerId)->values();
            if ($items->isEmpty()) throw ValidationException::withMessages(['status' => ['This parcel has nothing left to fulfil.']]);

            $from = $ladder[$target - 1];
            $moving = $items->where('fulfillment_status', $from);
            $behind = $items->first(fn (OrderItem $item) => ($i = array_search($item->fulfillment_status, $ladder, true)) !== false && $i < $target - 1);
            if ($moving->isEmpty() || $behind) {
                $at = $only?->fresh()->fulfillment_status ?? ($behind?->fulfillment_status ?? $items->first()->fulfillment_status);
                $next = $ladder[(array_search($at, $ladder, true) ?: 0) + 1] ?? null;
                throw ValidationException::withMessages(['status' => [$only ? "Item must transition from {$at} to {$next}." : "Parcel must move from {$at} to {$next} first."]]);
            }

            $nexfBooks = $lockedGroup->ships_by === 'nexf' && !$lockedOrder->manual_ship;
            // A store that connected its own Steadfast account gets the same automatic booking, on its account.
            $storeBooks = $lockedGroup->ships_by === 'seller' && !$lockedOrder->manual_ship && SteadfastService::storeConnected($lockedGroup->seller);
            switch ($data['status']) {
                case 'confirmed':
                    foreach ($moving as $item) {
                        if ($item->awaiting_stock) $this->takeAwaitedStock($item, $actor);
                        $item->update(['fulfillment_status' => 'confirmed']);
                    }
                    break;
                case 'packed':
                    foreach ($moving as $item) $item->update(['fulfillment_status' => 'packed']);
                    // Packing hands the parcel to Steadfast (NEXF's or the store's own account); a refusal aborts the step.
                    if (($nexfBooks || $storeBooks) && !$lockedGroup->courier_consignment_id) {
                        $lockedGroup->load(['order.customer', 'items']);
                        $courier = $this->steadfast->createConsignment($lockedGroup);
                        $this->markGroupShipped($lockedGroup, [
                            'courier_provider' => $courier['provider'],
                            'tracking_number' => $courier['tracking_code'],
                            'courier_tracking_url' => $courier['tracking_url'],
                            'courier_consignment_id' => $courier['consignment_id'],
                            'courier_invoice' => $courier['invoice'],
                            'courier_status' => $courier['status'],
                        ]);
                    }
                    break;
                case 'shipped':
                    if ($nexfBooks) throw ValidationException::withMessages(['status' => ['NEXF parcels are booked with Steadfast when they are packed.']]);
                    if ($lockedGroup->ships_by === 'seller' && empty($data['courier_provider'])) {
                        throw ValidationException::withMessages(['courier_provider' => ['Parcel service is required when marking an item shipped.']]);
                    }
                    if (empty($data['tracking_number'])) {
                        throw ValidationException::withMessages(['tracking_number' => ["Enter the courier's tracking code for this parcel."]]);
                    }
                    $this->markGroupShipped($lockedGroup, [
                        'courier_provider' => $data['courier_provider'] ?? $lockedGroup->courier_provider ?? 'steadfast',
                        'tracking_number' => $data['tracking_number'],
                        'courier_tracking_url' => $data['tracking_url'] ?? null,
                        'courier_consignment_id' => null,
                        'courier_invoice' => $lockedGroup->number,
                    ]);
                    break;
                case 'delivered':
                    foreach ($moving as $item) $item->update(['fulfillment_status' => 'delivered', 'delivered_at' => now()]);
                    break;
            }

            $this->subOrders->refreshStatus($lockedGroup->fresh());
            $this->refreshOrderStatus($lockedOrder);

            return $lockedOrder->load(['customer', 'items', 'storeGroups', 'paymentMethod', 'shippingMethod', 'coupon']);
        });

        $this->notifications->statusChanged($order);

        if ($sellerId !== null) {
            $order = $this->sellerView($order->load([
                'customer:id,name,email,phone',
                'items' => fn ($query) => $query->where('seller_id', $sellerId),
                'storeGroups' => $this->sellerGroups($sellerId),
                'shippingMethod',
            ])->toArray(), $sellerId);
        }

        return response()->json([
            'message' => 'Item fulfilment updated successfully.',
            'order' => $order,
        ]);
    }

    private function takeAwaitedStock(OrderItem $item, $actor): void
    {
        $quantity = (int) $item->quantity;
        $variation = $item->variation_id ? ProductVariation::query()->find($item->variation_id) : null;
        $product = $item->product_id ? Product::query()->find($item->product_id) : null;
        if (!$variation && !$product) throw ValidationException::withMessages(['status' => ["{$item->product_name} is no longer in the catalogue, so its stock cannot be taken."]]);
        try { $result = $variation ? $this->inventory->consumeVariation($variation, $quantity, $actor, 'order_sale', ['order_id' => $item->order_id, 'stock_request' => true]) : $this->inventory->consumeProduct($product, $quantity, $actor, 'order_sale', ['order_id' => $item->order_id, 'stock_request' => true]); }
        catch (ValidationException $e) { throw ValidationException::withMessages(['status' => ["{$item->product_name} is still out of stock. Add stock before confirming this request."]]); }
        $cost=(float)$result['totals']['cost']; $revenue=(float)$item->line_subtotal;
        $item->update(['lot_allocations'=>$result['allocations'],'unit_buying_price'=>round($cost/max(1,$quantity),2),'line_cost'=>$cost,'line_profit'=>round($revenue-$cost,2),'awaiting_stock'=>false]);
    }

    public function refreshOrderStatus(Order $order): void
    {
        foreach ($order->storeGroups()->get() as $group) $this->subOrders->refreshStatus($group);
        $statuses = $order->items()->pluck('fulfillment_status');
        if ($statuses->isEmpty()) {
            return;
        }

        if ($statuses->every(fn (string $status) => in_array($status, ['returned', 'cancelled'], true))) {
            $order->update(['status' => 'cancelled', 'cancelled_at' => $order->cancelled_at ?? now(), 'cancelled_by' => $order->cancelled_by ?? 'system']);
            return;
        }

        $rank = ['pending' => 0, 'confirmed' => 1, 'packed' => 2, 'shipped' => 3, 'delivered' => 4, 'return_pending' => 5, 'returned' => 6];
        $activeStatuses = $statuses->reject(fn (string $status) => $status === 'cancelled');
        $lowest = $activeStatuses->sortBy(fn (string $status) => $rank[$status] ?? 0)->first();
        $allPackedOrFurther = $activeStatuses->every(fn (string $status) => ($rank[$status] ?? 0) >= $rank['packed']);
        $order->update([
            'status' => $lowest,
            'packed_at' => $allPackedOrFurther ? ($order->packed_at ?? now()) : null,
        ]);
        if (in_array($lowest, ['delivered', 'completed'], true)) app(\App\Services\WalletRewardService::class)->orderDelivered($order->fresh());
    }

    public function store(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $isGuestCheckout = (bool) $request->attributes->get('guest_checkout', false);
        $isAdminOrder = !$isGuestCheckout && ($request->user() instanceof Admin || $request->attributes->has('order_actor'));
        $actor = $request->attributes->get('order_actor') ?? $customer;
        $isGuestOrder = $isGuestCheckout || ($isAdminOrder && (bool) $request->attributes->get('admin_guest', false));

        if (!$isAdminOrder && !$isGuestCheckout && !$customer instanceof Customer) {
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
        if ($isGuestCheckout) $this->assertGuestPhone($data['shipping_phone']);

        if (!$isAdminOrder) {
            $this->turnstile->assertHuman($request, $isGuestCheckout);
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
            // An exchange is a swap with the store that sold the item; another store's goods are a new order.
            $sellerIds = Product::query()->whereIn('id', collect($data['items'])->pluck('product_id'))->pluck('seller_id')->unique();
            if ($sellerIds->contains(fn ($id) => (int) $id !== (int) $exchangeReturn->seller_id || ($id === null) !== ($exchangeReturn->seller_id === null))) {
                throw ValidationException::withMessages(['items' => ['An exchange can only use products from the store that sold the returned item.']]);
            }
        }

        // Uses the same validation, availability, and pricing rules as checkout preview.
        $data['ip_address'] = $request->ip();
        $data['staff_order'] = $isAdminOrder || $actor instanceof Seller;
        $preview = $this->checkout->preview($customer, $data);

        $order = DB::transaction(function () use ($customer, $actor, $isGuestOrder, $data, $preview, $exchangeReturn) {
            $paymentMethod = PaymentMethod::query()->active()->find($data['payment_method_id']);
            if (!$paymentMethod) {
                throw ValidationException::withMessages([
                    'payment_method_id' => ['Selected payment method is unavailable.'],
                ]);
            }

            $shippingMethod = $preview['shippingMethod'];

            $useWallet = $paymentMethod->code === 'wallet' || !empty($data['use_wallet']);
            $walletAmount = 0.0;
            $walletRewardAmount = 0.0;
            $walletWithdrawableAmount = 0.0;
            if ($useWallet) {
                if (!$customer) throw ValidationException::withMessages(['payment_method_id' => ['NEXF Balance is available only to signed-in customers.']]);
                Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
                $buckets = app(WalletBalanceService::class)->buckets($customer->id, true);
                if ($paymentMethod->code === 'wallet' && $buckets['total'] < (float) $preview['total']) {
                    throw ValidationException::withMessages(['payment_method_id' => ['Your NEXF Balance is not enough for this order.']]);
                }
                $walletAmount = min($buckets['total'], (float) $preview['total']);
                $walletRewardAmount = min($buckets['reward'], $walletAmount);
                $walletWithdrawableAmount = $walletAmount - $walletRewardAmount;
            }

            $order = Order::create([
                // The customer's order is always NEXF's; each store's part is numbered under it (NEXF-00024-SADK).
                'order_number' => $this->generateOrderNumber($exchangeReturn ? 'EXC' : 'NEXF'),
                'customer_id' => $customer?->id,
                'reseller_id' => $customer?->reseller_id,
                'reseller_commission_rate' => $customer?->reseller?->commission_rate,
                'reseller_monthly_target' => $customer?->reseller?->monthly_target,
                'payment_method_id' => $paymentMethod->id,
                'shipping_method_id' => $shippingMethod->id,
                'status' => $exchangeReturn ? 'confirmed' : 'pending',
                'payment_status' => ($exchangeReturn || $paymentMethod->code === 'wallet' || $walletAmount >= (float) $preview['total']) ? 'paid' : 'unpaid',
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
                'wallet_amount' => $walletAmount,
                'wallet_reward_amount' => $walletRewardAmount,
                'wallet_withdrawable_amount' => $walletWithdrawableAmount,
                'manual_ship' => (bool) $exchangeReturn,
                'stock_request' => !$exchangeReturn && !empty($data['stock_request']),
                'exchange_for' => $exchangeReturn ? (string) $exchangeReturn->id : null,
                'manual_discount_kind' => $data['manual_discount_kind'] ?? null,
                'manual_discount_value' => $data['manual_discount_value'] ?? null,
                'internal_note' => $data['internal_note'] ?? null,
                'shipping_name' => $data['shipping_name'],
                'shipping_phone' => $data['shipping_phone'],
                'shipping_email' => $data['shipping_email'] ?? $customer?->email,
                'is_guest' => $isGuestOrder,
                'staff_order' => (bool) $data['staff_order'],
                'ip_address' => $data['ip_address'],
                'device_id' => $data['device_id'] ?? null,
                'shipping_address' => $data['shipping_address'],
                'shipping_area' => $data['shipping_area'] ?? null,
                'shipping_district' => $data['shipping_district'] ?? null,
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

                    $result = $order->stock_request
                        ? ['allocations' => [], 'totals' => ['cost' => 0.0, 'revenue' => round($this->inventory->unitSellingPrice($product, $variation) * $quantity, 2), 'profit' => round($this->inventory->unitSellingPrice($product, $variation) * $quantity, 2)]]
                        : $this->inventory->consumeVariation($variation, $quantity, $actor, 'order_sale', ['order_id' => $order->id]);
                } else {
                    $result = $order->stock_request
                        ? ['allocations' => [], 'totals' => ['cost' => 0.0, 'revenue' => round($this->inventory->unitSellingPrice($product) * $quantity, 2), 'profit' => round($this->inventory->unitSellingPrice($product) * $quantity, 2)]]
                        : $this->inventory->consumeProduct($product, $quantity, $actor, 'order_sale', ['order_id' => $order->id]);
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
                    'variation_labels' => $this->variationLabels($product, $variation),
                    'variation_image' => $variation?->image_url,
                    'quantity' => $quantity,
                    'unit_selling_price' => $unitSellingPrice,
                    'unit_buying_price' => $unitBuyingPrice,
                    'line_subtotal' => $lineSubtotal,
                    'line_cost' => $lineCost,
                    'line_profit' => $lineProfit,
                    'commission_rate' => $product->seller?->commission_rate,
                    'lot_allocations' => $result['allocations'],
                    'awaiting_stock' => $order->stock_request,
                ]);

                $subtotal = round($subtotal + $lineSubtotal, 2);
                $shippingLines[] = ['product' => $product, 'subtotal' => $lineSubtotal];
            }

            $shipping = $this->storeShipping->quote($shippingLines, $shippingMethod, $data['store_shipping_methods'] ?? [], $data['store_delivery_options'] ?? []);
            $appliedDiscounts = $this->lockAppliedDiscounts($preview, $customer, $data['shipping_phone']);
            $coupon = $appliedDiscounts[0]['coupon'] ?? null;
            $couponDiscount = round(array_sum(array_column($appliedDiscounts, 'amount')), 2);
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
                'wallet_amount' => min($walletAmount, $total),
                'wallet_reward_amount' => min($walletRewardAmount, $total),
                'wallet_withdrawable_amount' => min($walletWithdrawableAmount, max(0, $total - min($walletRewardAmount, $total))),
                'payment_status' => $walletAmount >= $total ? 'paid' : $order->payment_status,
            ])->save();

            if ((float) $order->wallet_reward_amount > 0) {
                CustomerWalletTransaction::create(['customer_id' => $customer->id, 'order_id' => $order->id, 'type' => 'debit', 'kind' => 'spend', 'bucket' => 'reward', 'amount' => $order->wallet_reward_amount, 'source_key' => "order:{$order->id}:wallet:reward", 'description' => "Payment for order {$order->order_number}"]);
            }
            if ((float) $order->wallet_withdrawable_amount > 0) {
                CustomerWalletTransaction::create(['customer_id' => $customer->id, 'order_id' => $order->id, 'type' => 'debit', 'kind' => 'spend', 'bucket' => 'withdrawable', 'amount' => $order->wallet_withdrawable_amount, 'source_key' => "order:{$order->id}:wallet:withdrawable", 'description' => "Payment for order {$order->order_number}"]);
            }

            foreach ($appliedDiscounts as $appliedDiscount) {
                $appliedCoupon = $appliedDiscount['coupon'];
                $appliedCoupon->increment('used_count');
                CouponRedemption::create([
                    'coupon_id' => $appliedCoupon->id,
                    'customer_id' => $customer?->id,
                    'order_id' => $order->id,
                    'coupon_code' => $appliedCoupon->code,
                    'coupon_name' => $appliedCoupon->name,
                    'is_automatic' => $appliedCoupon->is_automatic,
                    'order_subtotal' => $subtotal,
                    'discount_amount' => $appliedDiscount['amount'],
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

            $this->subOrders->sync($order);
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

    /** Lock and recheck every discount that contributed to a checkout quote. */
    private function lockAppliedDiscounts(array $preview, ?Customer $customer, ?string $shippingPhone = null): array
    {
        $applied = [];
        foreach ($preview['applied_discounts'] ?? [] as $discount) {
            $coupon = Coupon::query()->lockForUpdate()->find($discount['coupon']->id);
            if (!$coupon) throw ValidationException::withMessages(['coupon_code' => ['A discount is no longer available.']]);
            if ($reason = $coupon->unusableReason()) throw ValidationException::withMessages(['coupon_code' => [$reason]]);
            if (app(\App\Services\CouponUsageLimitService::class)->reached($coupon, $customer, $shippingPhone)) {
                throw ValidationException::withMessages(['coupon_code' => ['Coupon usage limit reached for this customer.']]);
            }
            $applied[] = ['coupon' => $coupon, 'amount' => (float) $discount['amount']];
        }
        return $applied;
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
            'store_delivery_options' => ['nullable', 'array'],
            'store_delivery_options.*' => ['string', 'max:100'],
            'device_id' => ['nullable', 'string', 'regex:/^[a-f0-9]{16,64}$/i'],
            'shipping_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'shipping_area' => ['sometimes', 'nullable', 'string', 'max:150'],
            'shipping_district' => ['sometimes', 'nullable', 'string', 'max:150'],
            'stock_request' => ['sometimes', 'boolean'],
            'use_wallet' => ['sometimes', 'boolean'],
        ];
    }

    private function variationLabels(Product $product, ?ProductVariation $variation): ?array
    {
        if (!$variation) return null;
        $attributes = $variation->attributes ?? [];
        $labels = [];
        $handledKeys = [];
        foreach ($product->option_groups ?? [] as $group) {
            $key = (string) ($group['key'] ?? '');
            if ($key === '' || !array_key_exists($key, $attributes)) continue;
            $selected = collect($group['values'] ?? [])->first(fn (array $value) => (string) ($value['value'] ?? '') === (string) $attributes[$key]);
            $labels[] = ['group' => $group['label'] ?? $key, 'value' => $selected['label'] ?? (string) $attributes[$key], 'swatch' => $selected['swatch'] ?? null];
            $handledKeys[] = $key;
        }
        foreach ($attributes as $key => $value) if (!in_array((string) $key, $handledKeys, true)) $labels[] = ['group' => (string) $key, 'value' => (string) $value, 'swatch' => null];
        return $labels;
    }

    private function assertGuestPhone(string $phone): void
    {
        if (!preg_match('/^01[3-9][0-9]{8}$/', $phone)) {
            throw ValidationException::withMessages(['shipping_phone' => ['Enter a valid Bangladesh mobile number.']]);
        }
    }

    /**
     * Sell one requested line on an order being edited: check the product and
     * variation, take the stock (unless the order waits on a stock request) and
     * create the line. `$sellerIds`, when given, limits whose products it takes.
     */
    private function addEditedLine(Order $order, array $itemData, $actor, string $state, bool $keepRequest, ?array $sellerIds = null, array $attributes = []): array
    {
        $product = Product::query()->with('seller')->availableForSale()->find($itemData['product_id']);
        if (!$product || ($product->seller_id !== null && (!$product->seller || $product->seller->status !== 'approved' || !$product->seller->is_active))) {
            throw ValidationException::withMessages(['items' => ['One or more products are unavailable.']]);
        }
        if ($sellerIds !== null && !in_array($product->seller_id, $sellerIds, true)) {
            throw ValidationException::withMessages(['items' => ["{$product->name} doesn't ship in this parcel."]]);
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
        }
        if ($keepRequest) {
            $revenue = round($this->inventory->unitSellingPrice($product, $variation) * $quantity, 2);
            $result = ['allocations' => [], 'totals' => ['cost' => 0.0, 'revenue' => $revenue, 'profit' => $revenue]];
        } elseif ($variation) {
            $result = $this->inventory->consumeVariation($variation, $quantity, $actor, 'order_sale', ['order_id' => $order->id]);
        } else {
            $result = $this->inventory->consumeProduct($product, $quantity, $actor, 'order_sale', ['order_id' => $order->id]);
        }
        $lineSubtotal = (float) $result['totals']['revenue'];
        $lineCost = (float) $result['totals']['cost'];
        $order->items()->create([
            'product_id' => $product->id, 'variation_id' => $variation?->id, 'seller_id' => $product->seller_id,
            'product_name' => $product->name, 'product_slug' => $product->slug, 'product_thumbnail' => $product->thumbnail,
            'product_gallery' => $product->gallery, 'product_image_variants' => $product->image_variants,
            'sku' => $variation?->sku, 'variation_attributes' => $variation?->attributes, 'quantity' => $quantity,
            'variation_labels' => $this->variationLabels($product, $variation), 'variation_image' => $variation?->image_url,
            'unit_selling_price' => round($lineSubtotal / $quantity, 2), 'unit_buying_price' => round($lineCost / $quantity, 2),
            'line_subtotal' => $lineSubtotal, 'line_cost' => $lineCost, 'line_profit' => (float) $result['totals']['profit'], 'commission_rate' => $product->seller?->commission_rate,
            'lot_allocations' => $result['allocations'], 'fulfillment_status' => $state, 'awaiting_stock' => $keepRequest,
        ] + $attributes);
        return [$product, $lineSubtotal];
    }

    /** Lines (order items or submitted lines) as "product:variation" => quantity, to tell whether a parcel's lines changed. */
    private function lineKeys($items): array
    {
        $keys = [];
        foreach ($items as $item) {
            $key = data_get($item, 'product_id') . ':' . (data_get($item, 'variation_id') ?? '');
            $keys[$key] = ($keys[$key] ?? 0) + (int) data_get($item, 'quantity');
        }
        ksort($keys);
        return $keys;
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

    private function generateOrderNumber(string $prefix = 'NEXF'): string
    {
        $prefix = strtoupper($prefix);
        DB::table('order_number_sequences')->insertOrIgnore(['prefix' => $prefix, 'next_value' => 1]);
        $sequence = DB::table('order_number_sequences')->where('prefix', $prefix)->lockForUpdate()->first();
        $number = (int) $sequence->next_value;
        DB::table('order_number_sequences')->where('prefix', $prefix)->update(['next_value' => $number + 1]);

        return $prefix . '-' . str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }

    private function paginateOrderList($query, Request $request, ?int $sellerId = null): array
    {
        $filters = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'in:pending,confirmed,packed,shipped,delivered,completed,cancelled,return_pending'],
            'payment_status' => ['sometimes', 'in:unpaid,paid,failed,refunded'],
            'guest' => ['sometimes', 'boolean'],
            'exchange' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:100'],
        ]);
        if (isset($filters['payment_status'])) $query->where('payment_status', $filters['payment_status']);
        if (($filters['guest'] ?? false) === true || ($filters['guest'] ?? null) === '1') $query->where('is_guest', true);
        if (($filters['exchange'] ?? false) === true || ($filters['exchange'] ?? null) === '1') $query->whereNotNull('exchange_for');
        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(fn ($q) => $q->where('order_number', 'like', "%{$search}%")
                ->orWhere('shipping_name', 'like', "%{$search}%")
                ->orWhere('shipping_phone', 'like', "%{$search}%"));
        }
        $counts = (clone $query)->reorder()->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status')->map(fn ($count) => (int) $count)->all();
        if (isset($filters['status'])) $query->where('status', $filters['status']);
        $page = $query->paginate($filters['per_page'] ?? 25);
        $result = $page->toArray();
        if ($sellerId !== null) {
            $result['data'] = array_map(fn (array $order) => $this->sellerView($order, $sellerId), $result['data'] ?? []);
        }
        return array_merge($result, ['status_counts' => $counts]);
    }
}
