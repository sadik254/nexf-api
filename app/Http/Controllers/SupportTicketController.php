<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Seller;
use App\Models\SellerStaff;
use App\Models\Reseller;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Services\MediaUploadService;
use App\Services\TurnstileService;
use App\Services\ContactMessageCooldownService;

class SupportTicketController extends Controller
{
    public function __construct(private MediaUploadService $uploads, private TurnstileService $turnstile, private ContactMessageCooldownService $contactCooldown) {}

    public function contact(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:180'],
            'message' => ['required', 'string', 'max:5000'],
            'turnstile_token' => ['nullable', 'string', 'max:2048'],
        ]);
        $this->turnstile->assertHuman($request, false);
        $email = strtolower(trim($data['email']));
        $retryAfter = $this->contactCooldown->retryAfter((string) $request->ip(), $email);
        if ($retryAfter > 0) {
            return response()->json([
                'message' => 'Too many tries. Please try again later.',
                'retry_after' => $retryAfter,
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        $ticket = DB::transaction(function () use ($data, $email, $request) {
            $ticket = SupportTicket::create([
                'guest_name' => trim($data['name']), 'guest_email' => $email, 'contact_ip' => $request->ip(),
                'category' => 'Contact', 'subject' => trim($data['subject']),
            ]);
            $ticket->update(['reference' => 'SUP-' . str_pad((string) $ticket->id, 5, '0', STR_PAD_LEFT)]);
            $ticket->messages()->create(['author_type' => 'guest', 'author_id' => 0, 'body' => trim($data['message']), 'attachments' => []]);
            return $ticket;
        });
        app(\App\Services\ConsoleNotificationService::class)->support($ticket, 'new');
        return response()->json(['message' => 'Message received.', 'id' => $ticket->id, 'reference' => $ticket->reference], 201);
    }

    public function upload(Request $request): JsonResponse
    {
        $this->actor($request);
        $file = $request->validate(['file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm', 'max:20480']])['file'];
        return response()->json(['url' => $this->uploads->upload($file)]);
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $query = SupportTicket::query()->with(['customer:id,name,email', 'seller:id,store_name,store_slug', 'reseller:id,name,email', 'order:id,order_number'])
            ->withCount('messages')->latest('updated_at');
        if ($actor instanceof Customer) $query->where('customer_id', $actor->id);
        if ($actor instanceof Seller) $query->where('seller_id', $actor->id);
        if ($actor instanceof Reseller) $query->where('reseller_id', $actor->id);
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
        return response()->json($ticket->load(['customer:id,name,email', 'seller:id,store_name,store_slug', 'reseller:id,name,email', 'order:id,order_number', 'messages']));
    }

    public function store(Request $request): JsonResponse
    {
        $customer = $this->actor($request);
        abort_unless($customer instanceof Customer || $customer instanceof Reseller, 403);
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
        if ($customer instanceof Reseller && ($orderId || $sellerId)) abort(422, 'Reseller support requests cannot be linked to customer orders or stores.');
        if ($customer instanceof Customer && $orderId) {
            $order = Order::where('customer_id', $customer->id)->findOrFail($orderId);
            if ($sellerId && !$order->items()->where('seller_id', $sellerId)->exists()) {
                throw ValidationException::withMessages(['seller_id' => 'This store has no items in the selected order.']);
            }
        }
        $ticket = DB::transaction(function () use ($customer, $data, $orderId, $sellerId) {
            $ticket = SupportTicket::create([
                'customer_id' => $customer instanceof Customer ? $customer->id : null,
                'reseller_id' => $customer instanceof Reseller ? $customer->id : null,
                'order_id' => $orderId, 'seller_id' => $sellerId,
                'category' => trim($data['category']), 'subject' => trim($data['subject']),
            ]);
            $ticket->update(['reference' => 'SUP-' . str_pad((string) $ticket->id, 5, '0', STR_PAD_LEFT)]);
            $ticket->messages()->create(['author_type' => $customer instanceof Customer ? 'customer' : 'reseller', 'author_id' => $customer->id, 'body' => trim($data['body']), 'attachments' => $data['attachments'] ?? []]);
            return $ticket;
        });
        app(\App\Services\ConsoleNotificationService::class)->support($ticket->load('order:id,order_number'), 'new');
        return response()->json($ticket->load(['customer:id,name,email', 'seller:id,store_name,store_slug', 'reseller:id,name,email', 'order:id,order_number', 'messages']), 201);
    }

    /** Create a console ticket for a customer account or a guest contact. */
    public function storeConsole(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor instanceof Admin || $actor instanceof Seller, 403);
        $staff = $request->attributes->get('seller_staff');
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'guest_name' => ['required_without:customer_id', 'nullable', 'string', 'max:150'],
            'guest_phone' => ['required_without:customer_id', 'nullable', 'string', 'regex:/^01[3-9][0-9]{8}$/'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'seller_id' => ['nullable', 'integer', 'exists:sellers,id'],
            'no_store' => ['sometimes', 'boolean'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'category' => ['required', 'string', 'max:60'],
            'subject' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:5000'],
            'attachments' => ['sometimes', 'array', 'max:6'],
            'attachments.*' => ['required', 'url', 'max:1000', 'regex:/^https:\/\//'],
        ]);

        $sellerId = $actor instanceof Seller ? $actor->id : ($data['seller_id'] ?? null);
        $orderId = $data['order_id'] ?? null;
        if ($actor instanceof Seller && $orderId && !Order::query()->whereKey($orderId)->whereHas('items', fn ($items) => $items->where('seller_id', $actor->id))->exists()) {
            throw ValidationException::withMessages(['order_id' => ['The selected order does not contain an item from your store.']]);
        }

        $ticket = DB::transaction(function () use ($actor, $staff, $data, $sellerId, $orderId) {
            $ticket = SupportTicket::create([
                'customer_id' => $data['customer_id'] ?? null,
                'seller_id' => $sellerId,
                'order_id' => $orderId,
                'guest_name' => empty($data['customer_id']) ? trim((string) ($data['guest_name'] ?? '')) : null,
                'guest_phone' => empty($data['customer_id']) ? ($data['guest_phone'] ?? null) : null,
                'guest_email' => empty($data['customer_id']) ? ($data['guest_email'] ?? null) : null,
                // seller_id takes priority; null without this flag represents the house store.
                'no_store' => $actor instanceof Admin && !$sellerId && (bool) ($data['no_store'] ?? false),
                'category' => trim($data['category']),
                'subject' => trim($data['subject']),
            ]);
            $ticket->update(['reference' => 'SUP-' . str_pad((string) $ticket->id, 5, '0', STR_PAD_LEFT)]);
            $ticket->messages()->create([
                'author_type' => $staff instanceof SellerStaff ? 'seller_staff' : ($actor instanceof Seller ? 'seller' : 'admin'),
                'author_id' => $staff?->id ?? $actor->id,
                'author_name' => $staff?->name,
                'body' => trim($data['body']),
                'attachments' => $data['attachments'] ?? [],
            ]);
            return $ticket;
        });
        app(\App\Services\ConsoleNotificationService::class)->support($ticket->load('order:id,order_number'), 'new');

        return response()->json($ticket->load(['customer:id,name,email', 'seller:id,store_name,store_slug', 'reseller:id,name,email', 'order:id,order_number', 'messages']), 201);
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
        $staff = $request->attributes->get('seller_staff');
        DB::transaction(function () use ($ticket, $actor, $staff, $data) {
            $ticket->messages()->create([
                'author_type' => $staff instanceof SellerStaff ? 'seller_staff' : ($actor instanceof Customer ? 'customer' : ($actor instanceof Seller ? 'seller' : ($actor instanceof Reseller ? 'reseller' : 'admin'))),
                'author_id' => $staff?->id ?? $actor->id, 'author_name' => $staff?->name, 'body' => trim($data['body']), 'attachments' => $data['attachments'] ?? [],
            ]);
            $ticket->update(['status' => 'open', 'resolved_at' => null]);
        });
        app(\App\Services\ConsoleNotificationService::class)->support($ticket->fresh('order:id,order_number'), 'reply');
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

    private function actor(Request $request): Customer|Seller|Reseller|Admin
    {
        $actor = $request->user();
        abort_unless($actor instanceof Customer || $actor instanceof Seller || $actor instanceof Reseller || $actor instanceof Admin, 403);
        return $actor;
    }

    private function authorizeTicket(Customer|Seller|Reseller|Admin $actor, SupportTicket $ticket): void
    {
        if ($actor instanceof Customer) abort_unless($ticket->customer_id === $actor->id, 404);
        if ($actor instanceof Seller) abort_unless($ticket->seller_id === $actor->id, 404);
        if ($actor instanceof Reseller) abort_unless($ticket->reseller_id === $actor->id, 404);
    }
}
