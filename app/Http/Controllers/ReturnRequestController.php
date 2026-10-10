<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\OrderItem;
use App\Models\ReturnRequest;
use App\Models\Seller;
use App\Models\SupportTicket;
use App\Models\CustomerWalletTransaction;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReturnRequestController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate(['status' => ['sometimes', Rule::in(['requested', 'approved', 'rejected', 'in_transit', 'received', 'refunded', 'exchanged', 'cancelled'])], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = ReturnRequest::query()->with(['item:id,order_id,product_name,product_thumbnail,quantity,unit_selling_price', 'order:id,order_number', 'customer:id,name,email', 'seller:id,store_name'])->latest();
        if ($actor instanceof Customer) $query->where('customer_id', $actor->id);
        if ($actor instanceof Seller) $query->where('seller_id', $actor->id);
        if (isset($data['status'])) $query->where('status', $data['status']);
        return response()->json($query->paginate($data['per_page'] ?? 20));
    }

    public function show(Request $request, ReturnRequest $returnRequest): JsonResponse
    {
        $this->authorizeRequest($this->actor($request), $returnRequest);
        return response()->json($returnRequest->load([
            'item',
            'order:id,order_number,customer_id,payment_method_id,payment_method_name,payment_status,total,shipping_name,shipping_phone,shipping_email,shipping_address,status,created_at',
            'order.paymentMethod:id,name,code',
            'exchangeOrder:id,order_number,status,total,exchange_for',
            'customer:id,name,email,phone',
            'seller:id,store_name',
            'ticket:id,subject',
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $customer = $this->actor($request);
        abort_unless($customer instanceof Customer, 403);
        $data = $request->validate([
            'order_item_id' => ['required', 'integer', 'exists:order_items,id'],
            'support_ticket_id' => ['nullable', 'integer', 'exists:support_tickets,id'],
            'type' => ['required', Rule::in(['refund', 'exchange'])],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:3000'],
        ]);
        $item = OrderItem::with('order:id,customer_id')->findOrFail($data['order_item_id']);
        abort_unless($item->order->customer_id === $customer->id, 404);
        if ($item->fulfillment_status !== 'delivered') throw ValidationException::withMessages(['order_item_id' => 'Only delivered items can be returned.']);
        if ($data['quantity'] > $item->quantity) throw ValidationException::withMessages(['quantity' => 'Quantity exceeds the delivered quantity.']);
        if (!empty($data['support_ticket_id'])) {
            $ticket = SupportTicket::where('customer_id', $customer->id)->findOrFail($data['support_ticket_id']);
            if ($ticket->order_id !== $item->order_id || ($ticket->seller_id && $ticket->seller_id !== $item->seller_id)) throw ValidationException::withMessages(['support_ticket_id' => 'The support ticket does not match this order and store.']);
        }
        $return = DB::transaction(function () use ($item, $customer, $data) {
            $lockedItem = OrderItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($lockedItem->fulfillment_status !== 'delivered') throw ValidationException::withMessages(['order_item_id' => 'Only delivered items can be returned.']);
            $claimed = ReturnRequest::where('order_item_id', $lockedItem->id)->whereNotIn('status', ['rejected', 'cancelled'])->sum('quantity');
            if ($claimed + $data['quantity'] > $lockedItem->quantity) throw ValidationException::withMessages(['quantity' => 'This item quantity is already in a return request.']);
            return ReturnRequest::create(['order_item_id' => $lockedItem->id, 'order_id' => $lockedItem->order_id, 'customer_id' => $customer->id, 'seller_id' => $lockedItem->seller_id, 'support_ticket_id' => $data['support_ticket_id'] ?? null, 'type' => $data['type'], 'status' => 'requested', 'quantity' => $data['quantity'], 'reason' => trim($data['reason'])]);
        });
        return response()->json($return->load(['item', 'order:id,order_number', 'seller:id,store_name']), 201);
    }

    public function update(Request $request, ReturnRequest $returnRequest): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeRequest($actor, $returnRequest);
        abort_if($actor instanceof Customer, 403);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['approved', 'rejected', 'in_transit', 'received', 'refunded', 'exchanged', 'cancelled'])],
            'decision_note' => ['nullable', 'string', 'max:3000'],
            'return_tracking' => ['nullable', 'string', 'max:255'],
            'outcome_reference' => ['nullable', 'string', 'max:255'],
            'refund_amount' => ['nullable', 'numeric', 'min:0'],
            'exchange_order_id' => ['sometimes', 'nullable', 'integer', 'exists:orders,id'],
        ]);
        if (!isset($data['status'])) {
            if (!array_key_exists('decision_note', $data) && !array_key_exists('return_tracking', $data)) {
                throw ValidationException::withMessages(['return_request' => ['Provide an internal note or tracking update.']]);
            }
            $returnRequest->update([
                'decision_note' => $data['decision_note'] ?? $returnRequest->decision_note,
                'return_tracking' => $data['return_tracking'] ?? $returnRequest->return_tracking,
            ]);
            return $this->show($request, $returnRequest);
        }
        $allowed = ['requested' => ['approved', 'rejected', 'cancelled'], 'approved' => ['in_transit', 'received', 'cancelled'], 'in_transit' => ['received', 'cancelled'], 'received' => [$returnRequest->type === 'refund' ? 'refunded' : 'exchanged']];
        $next = $data['status'];
        if ($actor instanceof Seller && $next === 'refunded') abort(403, 'Only an administrator can record a completed refund.');
        if (!in_array($next, $allowed[$returnRequest->status] ?? [], true)) throw ValidationException::withMessages(['status' => 'Invalid return transition.']);
        if ($next === 'in_transit' && empty($data['return_tracking'])) throw ValidationException::withMessages(['return_tracking' => 'Enter the courier tracking code before marking this return in transit.']);
        if ($next === 'exchanged') {
            $exchangeOrderId = $data['exchange_order_id'] ?? $returnRequest->exchange_order_id;
            $exchangeOrder = $exchangeOrderId ? \App\Models\Order::query()->find($exchangeOrderId) : null;
            if (
                !$exchangeOrder
                || (int) $exchangeOrder->customer_id !== (int) $returnRequest->customer_id
                || (string) $exchangeOrder->exchange_for !== (string) $returnRequest->id
                || $exchangeOrder->status !== 'delivered'
            ) {
                throw ValidationException::withMessages(['exchange_order_id' => ['Link a delivered replacement order for this customer before completing the exchange.']]);
            }
            $data['exchange_order_id'] = $exchangeOrder->id;
            $data['outcome_reference'] = $exchangeOrder->order_number;
        }
        if ($next === 'refunded' && empty($data['outcome_reference'])) throw ValidationException::withMessages(['outcome_reference' => 'Record the payment reference before completion.']);
        if ($next === 'refunded') {
            $maximum = (float) $returnRequest->item->unit_selling_price * $returnRequest->quantity;
            if (!isset($data['refund_amount']) || $data['refund_amount'] > $maximum) throw ValidationException::withMessages(['refund_amount' => 'A valid refund amount up to the item value is required.']);
        }
        $updates = ['status' => $next, 'decision_note' => $data['decision_note'] ?? $returnRequest->decision_note, 'return_tracking' => $data['return_tracking'] ?? $returnRequest->return_tracking];
        if (array_key_exists('exchange_order_id', $data)) $updates['exchange_order_id'] = $data['exchange_order_id'];
        if (in_array($next, ['approved', 'rejected'], true)) $updates['reviewed_at'] = now();
        if ($next === 'received') $updates['received_at'] = now();
        if (in_array($next, ['refunded', 'exchanged'], true)) { $updates['completed_at'] = now(); $updates['outcome_reference'] = $data['outcome_reference']; $updates['refund_amount'] = $data['refund_amount'] ?? null; }
        DB::transaction(function () use ($returnRequest, $updates, $next) {
            $returnRequest->update($updates);
            if ($next === 'refunded') {
                CustomerWalletTransaction::firstOrCreate(
                    ['return_request_id' => $returnRequest->id],
                    ['customer_id' => $returnRequest->customer_id, 'type' => 'credit', 'amount' => $updates['refund_amount'], 'description' => "Refund for return #{$returnRequest->id}"],
                );
            }
        });
        return $this->show($request, $returnRequest);
    }

    public function restock(Request $request, ReturnRequest $returnRequest): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeRequest($actor, $returnRequest);
        abort_if($actor instanceof Customer, 403);

        $updated = DB::transaction(function () use ($returnRequest, $actor) {
            $locked = ReturnRequest::query()->lockForUpdate()->findOrFail($returnRequest->id);
            if ($locked->restocked_at) return $locked;
            if (!in_array($locked->status, ['received', 'refunded', 'exchanged'], true)) {
                throw ValidationException::withMessages(['status' => ['Restock is available after staff have received the returned item.']]);
            }
            $item = OrderItem::query()->lockForUpdate()->findOrFail($locked->order_item_id);
            $this->inventory->restoreOrderItemQuantity($item, (int) $locked->quantity, $actor, 'return_restock', [
                'return_request_id' => $locked->id,
            ]);
            $locked->update(['restocked_at' => now()]);
            return $locked;
        });

        return response()->json($updated->fresh()->load([
            'item', 'order:id,order_number,customer_id,payment_method_id,payment_method_name,payment_status,total,shipping_name,shipping_phone,shipping_email,shipping_address,status,created_at',
            'order.paymentMethod:id,name,code', 'exchangeOrder:id,order_number,status,total,exchange_for', 'customer:id,name,email,phone', 'seller:id,store_name', 'ticket:id,subject',
        ]));
    }

    private function actor(Request $request): Customer|Seller|Admin
    {
        $actor = $request->user();
        abort_unless($actor instanceof Customer || $actor instanceof Seller || $actor instanceof Admin, 403);
        return $actor;
    }
    private function authorizeRequest(Customer|Seller|Admin $actor, ReturnRequest $return): void
    {
        if ($actor instanceof Customer) abort_unless($return->customer_id === $actor->id, 404);
        if ($actor instanceof Seller) abort_unless($return->seller_id === $actor->id, 404);
    }
}
