<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Product;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class InventoryController extends Controller
{
    public function indexAdmin(Request $request): JsonResponse
    {
        abort_unless($request->user() instanceof Admin, 403);
        return response()->json($this->rows(Product::query()->whereNull('seller_id'), $request));
    }

    public function indexSeller(Request $request): JsonResponse
    {
        $seller = $request->user();
        abort_unless($seller instanceof Seller, 403);
        return response()->json($this->rows(Product::query()->where('seller_id', $seller->id), $request));
    }

    private function rows($query, Request $request): LengthAwarePaginator
    {
        return $query->withSum('lots as available_quantity', 'quantity_remaining')
            ->with([
                'lots:id,product_id,lot_number,buying_price,selling_price,quantity,quantity_remaining,received_at,expires_at',
                'variations' => fn ($variations) => $variations->withSum('lots as available_quantity', 'quantity_remaining')->with('lots:id,variation_id,lot_number,buying_price,selling_price,quantity,quantity_remaining,received_at,expires_at'),
            ])
            ->latest()
            ->get()
            ->flatMap(function (Product $product) {
                if ($product->product_type === 'simple') return [[
                    'product_id' => $product->id,
                    'variation_id' => null,
                    'product_name' => $product->name,
                    'sku' => null,
                    'attributes' => null,
                    'is_active' => $product->status === 'active',
                    'available_quantity' => (int) ($product->available_quantity ?? 0),
                    'lots' => $product->lots->values(),
                ]];
                return $product->variations->map(fn ($variation) => [
                    'product_id' => $product->id,
                    'variation_id' => $variation->id,
                    'product_name' => $product->name,
                    'sku' => $variation->sku,
                    'attributes' => $variation->attributes,
                    'is_active' => $variation->is_active,
                    'available_quantity' => (int) ($variation->available_quantity ?? 0),
                    'lots' => $variation->lots->values(),
                ]);
            })->values()
            ->pipe(function ($rows) use ($request) {
                $perPage = max(1, min((int) $request->query('per_page', 25), 100));
                $page = max(1, (int) $request->query('page', 1));
                return new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]);
            });
    }
}
