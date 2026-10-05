<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Seller;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Services\MediaUploadService;

class SupportTicketController extends Controller
{
    public function __construct(private MediaUploadService $uploads) {}

    public function upload(Request $request): JsonResponse
    {
        $this->actor($request);
        $file = $request->validate(['file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm', 'max:20480']])['file'];
        return response()->json(['url' => $this->uploads->upload($file)]);
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $query = SupportTicket::query()->with(['customer:id,name,email', 'seller:id,store_name,store_slug', 'order:id,order_number'])
            ->withCount('messages')->latest('updated_at');
        if ($actor instanceof Customer) $query->where('customer_id', $actor->id);
        if ($actor instanceof Seller) $query->where('seller_id', $actor->id);
        if ($request->filled('status')) $query->where('status', $request->validate(['status' => ['required', Rule::in(['open', 'resolved'])]])['status']);
        if ($request->filled('search')) {
            $search = substr((string) $request->query('search'), 0, 100);
            $query->where(fn ($q) => $q->where('subject', 'like', "%{$search}%")->orWhere('category', 'like', "%{$search}%"));
        }
        return response()->json($query->paginate(max(1, min((int) $request->query('per_page', 20), 100))));
    }

    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorizeTicket($this->actor($request), $ticket);
        return response()->json($ticket->load(['customer:id,name,email', 'seller:id,store_name,store_slug', 'order:id,order_number', 'messages']));
    }

    public function store(Request $request): JsonResponse
    {
        $customer = $this->actor($request);
        abort_unless($customer instanceof Customer, 403);
        $data = $request->validate([
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'seller_id' => ['nullable', 'integer', 'exists:sellers,id'],
            'category' => ['required', 'string', 'max:60'],
            'subject' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:5000'],
            'attachments' => ['sometimes', 'array', 'max:6'],
            'attachments.*' => ['required', 'url', 'max:1000', 'regex:/^https:\/\//'],
        ]);
        $orderId = $data['order_id'] ?? null;
        $sellerId = $data['seller_id'] ?? null;
        if ($orderId) {
            $order = Order::where('customer_id', $customer->id)->findOrFail($orderId);
            if ($sellerId && !$order->items()->where('seller_id', $sellerId)->exists()) {
                throw ValidationException::withMessages(['seller_id' => 'This store has no items in the selected order.']);
            }
        }
        $ticket = DB::transaction(function () use ($customer, $data, $orderId, $sellerId) {
            $ticket = SupportTicket::create([
                'customer_id' => $customer->id, 'order_id' => $orderId, 'seller_id' => $sellerId,
                'category' => trim($data['category']), 'subject' => trim($data['subject']),
            ]);
            $ticket->messages()->create(['author_type' => 'customer', 'author_id' => $customer->id, 'body' => trim($data['body']), 'attachments' => $data['attachments'] ?? []]);
            return $ticket;
        });
        return response()->json($ticket->load(['customer:id,name,email', 'seller:id,store_name,store_slug', 'order:id,order_number', 'messages']), 201);
    }

    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeTicket($actor, $ticket);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'attachments' => ['sometimes', 'array', 'max:6'],
            'attachments.*' => ['required', 'url', 'max:1000', 'regex:/^https:\/\//'],
        ]);
        DB::transaction(function () use ($ticket, $actor, $data) {
            $ticket->messages()->create([
                'author_type' => $actor instanceof Customer ? 'customer' : ($actor instanceof Seller ? 'seller' : 'admin'),
                'author_id' => $actor->id, 'body' => trim($data['body']), 'attachments' => $data['attachments'] ?? [],
            ]);
            $ticket->update(['status' => 'open', 'resolved_at' => null]);
        });
        return $this->show($request, $ticket);
    }

    public function resolve(Request $request, SupportTicket $ticket): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeTicket($actor, $ticket);
        abort_if($actor instanceof Customer, 403);
        $ticket->update(['status' => 'resolved', 'resolved_at' => now()]);
        return $this->show($request, $ticket);
    }

    private function actor(Request $request): Customer|Seller|Admin
    {
        $actor = $request->user();
        abort_unless($actor instanceof Customer || $actor instanceof Seller || $actor instanceof Admin, 403);
        return $actor;
    }

    private function authorizeTicket(Customer|Seller|Admin $actor, SupportTicket $ticket): void
    {
        if ($actor instanceof Customer) abort_unless($ticket->customer_id === $actor->id, 404);
        if ($actor instanceof Seller) abort_unless($ticket->seller_id === $actor->id, 404);
    }
}
