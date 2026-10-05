<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Seller;
use App\Models\StoreChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreChatController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $query = StoreChat::query()->with(['customer:id,name,email,profile_picture', 'seller:id,store_name,store_slug,store_logo'])
            ->withCount('messages')->latest('updated_at');
        if ($actor instanceof Customer) $query->where('customer_id', $actor->id);
        if ($actor instanceof Seller) $query->where('seller_id', $actor->id);
        if ($request->filled('search')) {
            $search = substr((string) $request->query('search'), 0, 100);
            $query->where(fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                ->orWhereHas('seller', fn ($s) => $s->where('store_name', 'like', "%{$search}%")));
        }
        return response()->json($query->paginate(max(1, min((int) $request->query('per_page', 20), 100))));
    }

    public function show(Request $request, StoreChat $chat): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeChat($actor, $chat);
        $chat->update($actor instanceof Customer ? ['customer_read_at' => now()] : ['store_read_at' => now()]);
        return response()->json($chat->load(['customer:id,name,email,profile_picture', 'seller:id,store_name,store_slug,store_logo', 'messages']));
    }

    public function start(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor instanceof Customer, 403);
        $data = $request->validate([
            'seller_id' => ['nullable', 'integer', Rule::exists('sellers', 'id')->where('status', 'approved')->where('is_active', true)],
            'body' => ['required', 'string', 'max:5000'],
            'attachments' => ['sometimes', 'array', 'max:6'],
            'attachments.*' => ['required', 'url', 'max:1000', 'regex:/^https:\/\//'],
        ]);
        $sellerId = $data['seller_id'] ?? null;
        $chat = DB::transaction(function () use ($actor, $sellerId, $data) {
            $chat = StoreChat::firstOrCreate(['customer_id' => $actor->id, 'store_key' => $sellerId ? "seller:{$sellerId}" : 'platform'], ['seller_id' => $sellerId]);
            $chat->messages()->create(['author_type' => 'customer', 'author_id' => $actor->id, 'body' => trim($data['body']), 'attachments' => $data['attachments'] ?? []]);
            $chat->update(['customer_read_at' => now(), 'store_read_at' => null]);
            return $chat;
        });
        return $this->show($request, $chat);
    }

    public function reply(Request $request, StoreChat $chat): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeChat($actor, $chat);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'attachments' => ['sometimes', 'array', 'max:6'],
            'attachments.*' => ['required', 'url', 'max:1000', 'regex:/^https:\/\//'],
        ]);
        DB::transaction(function () use ($chat, $actor, $data) {
            $chat->messages()->create(['author_type' => $actor instanceof Customer ? 'customer' : ($actor instanceof Seller ? 'seller' : 'admin'), 'author_id' => $actor->id, 'body' => trim($data['body']), 'attachments' => $data['attachments'] ?? []]);
            $chat->update($actor instanceof Customer ? ['customer_read_at' => now(), 'store_read_at' => null] : ['store_read_at' => now(), 'customer_read_at' => null]);
        });
        return $this->show($request, $chat);
    }

    private function actor(Request $request): Customer|Seller|Admin
    {
        $actor = $request->user();
        abort_unless($actor instanceof Customer || $actor instanceof Seller || $actor instanceof Admin, 403);
        return $actor;
    }

    private function authorizeChat(Customer|Seller|Admin $actor, StoreChat $chat): void
    {
        if ($actor instanceof Customer) abort_unless($chat->customer_id === $actor->id, 404);
        if ($actor instanceof Seller) abort_unless($chat->seller_id === $actor->id, 404);
    }
}
