<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductVariationController extends Controller
{
    public function index(Product $product, Request $request): JsonResponse
    {
        $this->authorizeProduct($product, $request->user());

        return response()->json($product->variations()->latest()->get());
    }

    public function store(Product $product, Request $request): JsonResponse
    {
        $this->authorizeProduct($product, $request->user());

        $data = $request->validate([
            'sku' => ['nullable', 'string', 'max:255'],
            'attributes' => ['required', 'array'],
            'default_buying_price' => ['nullable', 'numeric', 'min:0'],
            'default_selling_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (!empty($data['sku']) && ProductVariation::where('sku', $data['sku'])->exists()) {
            return response()->json(['message' => 'SKU already taken.'], 422);
        }

        $variation = ProductVariation::create([
            'product_id' => $product->id,
            'sku' => $data['sku'] ?? null,
            'attributes' => $data['attributes'],
            'default_buying_price' => $data['default_buying_price'] ?? null,
            'default_selling_price' => $data['default_selling_price'] ?? null,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ]);

        return response()->json([
            'message' => 'Variation created successfully.',
            'variation' => $variation,
        ], 201);
    }

    public function update(Product $product, ProductVariation $variation, Request $request): JsonResponse
    {
        $this->authorizeProduct($product, $request->user());
        if ($variation->product_id !== $product->id) {
            return response()->json(['message' => 'Variation does not belong to product.'], 422);
        }

        $data = $request->validate([
            'sku' => ['sometimes', 'string', 'max:255'],
            'attributes' => ['sometimes', 'array'],
            'default_buying_price' => ['sometimes', 'numeric', 'min:0'],
            'default_selling_price' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('sku', $data) && $data['sku'] !== null) {
            $exists = ProductVariation::where('sku', $data['sku'])
                ->where('id', '!=', $variation->id)
                ->exists();
            if ($exists) {
                return response()->json(['message' => 'SKU already taken.'], 422);
            }
        }

        $variation->fill([
            'sku' => array_key_exists('sku', $data) ? $data['sku'] : $variation->sku,
            'attributes' => $data['attributes'] ?? $variation->attributes,
            'default_buying_price' => $data['default_buying_price'] ?? $variation->default_buying_price,
            'default_selling_price' => $data['default_selling_price'] ?? $variation->default_selling_price,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $variation->is_active,
        ])->save();

        return response()->json([
            'message' => 'Variation updated successfully.',
            'variation' => $variation,
        ]);
    }

    public function destroy(Product $product, ProductVariation $variation, Request $request): JsonResponse
    {
        $this->authorizeProduct($product, $request->user());
        if ($variation->product_id !== $product->id) {
            return response()->json(['message' => 'Variation does not belong to product.'], 422);
        }

        $variation->delete();

        return response()->json(['message' => 'Variation deleted successfully.']);
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
