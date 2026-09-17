<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Product;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function indexAdmin(Request $request): JsonResponse
    {
        abort_unless($request->user() instanceof Admin, 403);
        return response()->json($this->rows(Product::query()->whereNull('seller_id')));
    }

    public function indexSeller(Request $request): JsonResponse
    {
        $seller = $request->user();
        abort_unless($seller instanceof Seller, 403);
        return response()->json($this->rows(Product::query()->where('seller_id', $seller->id)));
    }

    private function rows($query): array
    {
        return $query->withSum('lots as available_quantity', 'quantity_remaining')
            ->with(['variations' => fn ($variations) => $variations->withSum('lots as available_quantity', 'quantity_remaining')])
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
                ]];
                return $product->variations->map(fn ($variation) => [
                    'product_id' => $product->id,
                    'variation_id' => $variation->id,
                    'product_name' => $product->name,
                    'sku' => $variation->sku,
                    'attributes' => $variation->attributes,
                    'is_active' => $variation->is_active,
                    'available_quantity' => (int) ($variation->available_quantity ?? 0),
                ]);
            })->values()->all();
    }
}
