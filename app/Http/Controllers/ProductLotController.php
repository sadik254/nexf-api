<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\ProductVariation;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductLotController extends Controller
{
    public function storeForProduct(Product $product, Request $request): JsonResponse
    {
        $this->authorizeProduct($product, $request->user());

        $data = $request->validate([
            'lot_number' => ['required', 'string', 'max:255'],
            'buying_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'quantity' => ['required', 'integer', 'min:1'],
            'received_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $lot = ProductLot::create([
            'product_id' => $product->id,
            'variation_id' => null,
            'lot_number' => $data['lot_number'],
            'buying_price' => $data['buying_price'],
            'selling_price' => $data['selling_price'],
            'quantity' => $data['quantity'],
            'quantity_remaining' => $data['quantity'],
            'received_at' => $data['received_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return response()->json([
            'message' => 'Lot added successfully.',
            'lot' => $lot,
        ], 201);
    }

    public function storeForVariation(Product $product, ProductVariation $variation, Request $request): JsonResponse
    {
        $this->authorizeProduct($product, $request->user());
        if ($variation->product_id !== $product->id) {
            return response()->json(['message' => 'Variation does not belong to product.'], 422);
        }

        $data = $request->validate([
            'lot_number' => ['required', 'string', 'max:255'],
            'buying_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'quantity' => ['required', 'integer', 'min:1'],
            'received_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $lot = ProductLot::create([
            'product_id' => null,
            'variation_id' => $variation->id,
            'lot_number' => $data['lot_number'],
            'buying_price' => $data['buying_price'],
            'selling_price' => $data['selling_price'],
            'quantity' => $data['quantity'],
            'quantity_remaining' => $data['quantity'],
            'received_at' => $data['received_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return response()->json([
            'message' => 'Lot added successfully.',
            'lot' => $lot,
        ], 201);
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
