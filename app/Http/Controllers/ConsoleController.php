<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\ReturnRequest;
use App\Models\Seller;
use App\Models\StoreChat;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsoleController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof Admin || $actor instanceof Seller, 403);
        $sellerId = $actor instanceof Seller ? $actor->id : null;
        $scope = fn ($query) => $query->when($sellerId !== null, fn ($q) => $q->where('seller_id', $sellerId));
        $support = $scope(SupportTicket::query())->where('status', 'open')->count();
        $returns = $actor instanceof Admin && !in_array($actor->role, ['super_admin', 'admin'], true)
            ? 0 : $scope(ReturnRequest::query())->whereIn('status', ['requested', 'approved', 'in_transit', 'received'])->count();
        // Admins can browse every conversation but only reply to platform chats.
        $chats = StoreChat::query()->when($sellerId !== null,
            fn ($q) => $q->where('seller_id', $sellerId), fn ($q) => $q->whereNull('seller_id'))
            ->whereHas('messages', fn ($q) => $q->where('author_type', 'customer')
                ->where(fn ($q) => $q->whereNull('store_chats.store_read_at')
                    ->orWhereColumn('store_chat_messages.created_at', '>', 'store_chats.store_read_at')))->count();
        // Count sold-out SKUs using the same scope and quantity source as inventory.
        $simple = $scope(Product::query())->where('product_type', 'simple')
            ->whereDoesntHave('lots', fn ($q) => $q->where('quantity_remaining', '>', 0))->count();
        $variations = ProductVariation::query()->whereHas('product', fn ($q) => $scope($q)->where('product_type', 'variable'))
            ->whereDoesntHave('lots', fn ($q) => $q->where('quantity_remaining', '>', 0))->count();
        return response()->json([
            'support' => $support, 'returns' => $returns, 'chats' => $chats,
            'inventory' => $simple + $variations,
            // These services have no persisted backend models yet.
            'reports' => 0, 'withdrawals' => 0,
        ]);
    }
}
