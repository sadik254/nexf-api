<?php

namespace App\Http\Controllers;

use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderReturnController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:return_pending,returned'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $items = OrderItem::query()
            ->whereIn('fulfillment_status', ['return_pending', 'returned'])
            ->when(isset($data['status']), fn ($query) => $query->where('fulfillment_status', $data['status']))
            ->with(['order:id,order_number,shipping_name,shipping_phone,created_at', 'seller:id,store_name'])
            ->latest()->paginate($data['per_page'] ?? 25);
        return response()->json($items);
    }
}
