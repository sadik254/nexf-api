<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\ProductLot;
use App\Models\ProductLotMovement;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductVariationController extends Controller
{
    /** Save a complete product variant matrix and its opening stock atomically. */
    public function syncMatrix(Product $product, Request $request): JsonResponse
    {
        $this->authorizeProduct($product, $request->user());
        $data = $request->validate([
            'variants' => ['required', 'array', 'min:1', 'max:100'],
            'variants.*.attributes' => ['required', 'array', 'min:1'],
            'variants.*.attributes.*' => ['required', 'string', 'max:100'],
            'variants.*.sku' => ['nullable', 'string', 'max:255'],
            'variants.*.image_url' => ['nullable', 'url', 'starts_with:https://', 'max:2048'],
            'variants.*.default_selling_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.buying_price' => ['required', 'numeric', 'min:0'],
            'variants.*.initial_quantity' => ['required', 'integer', 'min:0'],
        ]);

        $saved = DB::transaction(function () use ($product, $data, $request) {
            $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);
            if ($lockedProduct->product_type !== 'variable' || empty($lockedProduct->option_groups)) {
                throw ValidationException::withMessages(['variants' => ['Save product options before applying a variation matrix.']]);
            }
            $allowed = [];
            foreach ($lockedProduct->option_groups as $group) {
                $allowed[$group['key']] = collect($group['values'] ?? [])->pluck('value')->all();
            }
            $existing = ProductVariation::query()->where('product_id', $lockedProduct->id)->lockForUpdate()->get();
            $saved = [];
            $seen = [];
            foreach ($data['variants'] as $row) {
                $attributes = $row['attributes'];
                ksort($attributes);
                $attributeKeys = array_keys($attributes);
                $expectedKeys = array_keys($allowed);
                sort($attributeKeys);
                sort($expectedKeys);
                if ($attributeKeys !== $expectedKeys) {
                    throw ValidationException::withMessages(['variants' => ['Every matrix row must include each configured option exactly once.']]);
                }
                foreach ($attributes as $key => $value) {
                    if (!in_array($value, $allowed[$key] ?? [], true)) {
                        throw ValidationException::withMessages(['variants' => ['A matrix row contains an option value that is not configured for this product.']]);
                    }
                }
                $signature = json_encode($attributes, JSON_THROW_ON_ERROR);
                if (isset($seen[$signature])) {
                    throw ValidationException::withMessages(['variants' => ['A variant combination appears more than once.']]);
                }
                $seen[$signature] = true;
                $variation = $existing->first(function (ProductVariation $candidate) use ($attributes) {
                    $candidateAttributes = $candidate->attributes ?? [];
                    ksort($candidateAttributes);
                    return $candidateAttributes === $attributes;
                });

                if (!empty($row['sku'])) {
                    $skuTaken = ProductVariation::query()->where('sku', $row['sku'])
                        ->when($variation, fn ($query) => $query->where('id', '!=', $variation->id))
                        ->exists();
                    if ($skuTaken) throw ValidationException::withMessages(['variants' => ['A variant SKU is already in use.']]);
                }

                if (!$variation) {
                    $variation = ProductVariation::create([
                        'product_id' => $lockedProduct->id,
                        'attributes' => $attributes,
                        'sku' => $row['sku'] ?? null,
                        'image_url' => $row['image_url'] ?? null,
                        'default_selling_price' => $row['default_selling_price'] ?? $lockedProduct->default_selling_price,
                        'is_active' => true,
                    ]);
                    $existing->push($variation);
                } else {
                    $variation->fill([
                        'sku' => $row['sku'] ?? null,
                        'image_url' => $row['image_url'] ?? $variation->image_url,
                        'default_selling_price' => $row['default_selling_price'] ?? $lockedProduct->default_selling_price,
                        'is_active' => true,
                    ])->save();
                }

                $quantity = (int) $row['initial_quantity'];
                $lotNumber = "MATRIX-{$lockedProduct->id}-{$variation->id}";
                if ($quantity > 0 && !ProductLot::query()->where('variation_id', $variation->id)->where('lot_number', $lotNumber)->exists()) {
                    $lot = ProductLot::create([
                        'product_id' => null,
                        'variation_id' => $variation->id,
                        'lot_number' => $lotNumber,
                        'buying_price' => $row['buying_price'],
                        'selling_price' => $row['default_selling_price'] ?? $lockedProduct->default_selling_price ?? 0,
                        'quantity' => $quantity,
                        'quantity_remaining' => $quantity,
                        'received_at' => now(),
                    ]);
                    ProductLotMovement::create([
                        'product_lot_id' => $lot->id,
                        'quantity_change' => $quantity,
                        'reason' => 'received',
                        'actor_type' => $request->user()::class,
                        'actor_id' => $request->user()->id,
                        'meta' => ['lot_number' => $lotNumber, 'source' => 'variant_matrix'],
                    ]);
                }
                $saved[] = $variation;
            }
            return $saved;
        });

        return response()->json([
            'message' => 'Variant matrix saved.',
            'variations' => collect($saved)->map(fn (ProductVariation $variation) => $variation->loadSum('lots as available_quantity', 'quantity_remaining'))->values(),
        ]);
    }

    public function index(Product $product, Request $request): JsonResponse
    {
        $this->authorizeProduct($product, $request->user());

        return response()->json($product->variations()->withSum('lots as available_quantity', 'quantity_remaining')->latest()->get());
    }

    public function store(Product $product, Request $request): JsonResponse
    {
        $this->authorizeProduct($product, $request->user());

        $data = $request->validate([
            'sku' => ['nullable', 'string', 'max:255'],
            'image_url' => ['nullable', 'url', 'starts_with:https://', 'max:2048'],
            'attributes' => ['required', 'array'],
            'default_selling_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (!empty($data['sku']) && ProductVariation::where('sku', $data['sku'])->exists()) {
            return response()->json(['message' => 'SKU already taken.'], 422);
        }

        $variation = ProductVariation::create([
            'product_id' => $product->id,
            'sku' => $data['sku'] ?? null,
            'image_url' => $data['image_url'] ?? null,
            'attributes' => $data['attributes'],
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
            'image_url' => ['sometimes', 'nullable', 'url', 'starts_with:https://', 'max:2048'],
            'attributes' => ['sometimes', 'array'],
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
            'image_url' => array_key_exists('image_url', $data) ? $data['image_url'] : $variation->image_url,
            'attributes' => $data['attributes'] ?? $variation->attributes,
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

        if ($variation->orderItems()->exists()) {
            return response()->json([
                'message' => 'Variations referenced by orders cannot be deleted. Mark the variation inactive instead.',
            ], 422);
        }

        $variation->delete();

        return response()->json(['message' => 'Variation deleted successfully.']);
    }

    private function authorizeProduct(Product $product, $actor): void
    {
        if ($actor instanceof Seller && $product->seller_id !== $actor->id) {
            abort(403, 'Forbidden.');
        }

    }
}
