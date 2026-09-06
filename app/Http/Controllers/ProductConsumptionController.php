<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Seller;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductConsumptionController extends Controller
{
    public function __construct(private InventoryService $inventory)
    {
    }

    public function consumeProduct(Product $product, Request $request): JsonResponse
    {
        $actor = $request->user();
        $this->authorizeProduct($product, $actor);

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $result = $this->inventory->consumeProduct($product, (int) $data['quantity'], $actor, 'manual_test');

        return response()->json([
            'message' => 'Consumed successfully.',
            ...$result,
        ]);
    }

    public function consumeVariation(Product $product, ProductVariation $variation, Request $request): JsonResponse
    {
        $actor = $request->user();
        $this->authorizeProduct($product, $actor);

        if ($variation->product_id !== $product->id) {
            return response()->json(['message' => 'Variation does not belong to product.'], 422);
        }

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $result = $this->inventory->consumeVariation($variation, (int) $data['quantity'], $actor, 'manual_test');

        return response()->json([
            'message' => 'Consumed successfully.',
            ...$result,
        ]);
    }

    private function authorizeProduct(Product $product, $actor): void
    {
        if ($actor instanceof Seller && $product->seller_id !== $actor->id) {
            abort(403, 'Forbidden.');
        }

        if ($actor instanceof Admin && $product->seller_id !== null) {
            abort(403, 'Forbidden.');
        }
    }
}
