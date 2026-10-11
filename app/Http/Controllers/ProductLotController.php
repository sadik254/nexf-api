<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\ProductLotMovement;
use App\Models\ProductVariation;
use App\Models\Seller;
use App\Services\RestockAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductLotController extends Controller
{
    public function adjust(ProductLot $lot, Request $request): JsonResponse
    {
        $product = $lot->product ?? $lot->variation?->product;
        abort_unless($product, 404);
        $this->authorizeProduct($product, $request->user());
        $data = $request->validate([
            'quantity_change' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $updated = DB::transaction(function () use ($lot, $data, $request) {
            $locked = ProductLot::query()->lockForUpdate()->findOrFail($lot->id);
            $change = (int) $data['quantity_change'];
            if ($locked->quantity_remaining + $change < 0) {
                throw ValidationException::withMessages(['quantity_change' => ['Adjustment exceeds available stock.']]);
            }
            $locked->quantity_remaining += $change;
            if ($change > 0) $locked->quantity += $change;
            $locked->save();
            ProductLotMovement::create([
                'product_lot_id' => $locked->id,
                'quantity_change' => $change,
                'reason' => 'adjustment',
                'actor_type' => $request->user()::class,
                'actor_id' => $request->user()->id,
                'meta' => ['reason' => $data['reason'], 'note' => $data['note'] ?? null],
            ]);
            return $locked;
        });
        if ((int) $data['quantity_change'] > 0) app(RestockAlertService::class)->notify($product->id, $lot->variation_id);
        return response()->json(['message' => 'Stock adjusted.', 'lot' => $updated]);
    }

    public function setAvailable(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof Admin || $actor instanceof Seller, 403);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.variation_id' => ['nullable', 'integer', 'min:1'],
            'items.*.available_quantity' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = DB::transaction(function () use ($actor, $data, $request) {
            $result = [];
            foreach ($data['items'] as $item) {
                $product = Product::query()->with('variations')->lockForUpdate()->findOrFail($item['product_id']);
                $this->authorizeProduct($product, $actor);
                $variationId = $item['variation_id'] ?? null;
                if ($variationId !== null && !$product->variations->contains('id', $variationId)) {
                    throw ValidationException::withMessages(['items' => ['The variation does not belong to this product.']]);
                }
                $target = $variationId
                    ? ProductLot::query()->where('variation_id', $variationId)
                    : ProductLot::query()->where('product_id', $product->id)->whereNull('variation_id');
                $lots = $target->orderBy('received_at')->orderBy('id')->lockForUpdate()->get();
                if (!$lots->count()) {
                    $targetLot = ProductLot::create([
                        'product_id' => $variationId ? null : $product->id,
                        'variation_id' => $variationId,
                        'lot_number' => 'COUNT-' . now()->format('YmdHis') . '-' . $product->id . '-' . ($variationId ?? 'simple'),
                        'buying_price' => $variationId
                            ? (float) ($product->variations->firstWhere('id', $variationId)?->default_buying_price ?? $product->default_buying_price ?? 0)
                            : (float) ($product->default_buying_price ?? 0),
                        'selling_price' => $variationId
                            ? (float) ($product->variations->firstWhere('id', $variationId)?->default_selling_price ?? $product->default_selling_price ?? 0)
                            : (float) ($product->default_selling_price ?? 0),
                        'quantity' => $item['available_quantity'],
                        'quantity_remaining' => $item['available_quantity'],
                    ]);
                    $lots = collect([$targetLot]);
                    $old = 0;
                } else {
                    $old = (int) $lots->sum('quantity_remaining');
                    $remaining = $item['available_quantity'];
                    foreach ($lots as $lot) {
                        $next = min((int) $lot->quantity_remaining, $remaining);
                        $lot->update(['quantity_remaining' => $next]);
                        $remaining -= $next;
                    }
                    if ($remaining > 0) {
                        $lot = $lots->last();
                        $lot->update(['quantity' => $lot->quantity + $remaining, 'quantity_remaining' => $lot->quantity_remaining + $remaining]);
                        $lots->push($lot);
                    }
                }
                $change = $item['available_quantity'] - $old;
                if ($change !== 0) {
                    ProductLotMovement::create([
                        'product_lot_id' => $lots->last()->id,
                        'quantity_change' => $change,
                        'reason' => 'adjustment',
                        'actor_type' => $actor::class,
                        'actor_id' => $actor->id,
                        'meta' => ['reason' => $data['reason'], 'note' => $data['note'] ?? null, 'set_available' => true],
                    ]);
                }
                $result[] = ['product_id' => $product->id, 'variation_id' => $variationId, 'available_quantity' => $item['available_quantity']];
            }
            return $result;
        });

        return response()->json(['message' => 'Inventory counts saved.', 'items' => $updated]);
    }

    public function storeForProduct(Product $product, Request $request): JsonResponse
    {
        $this->authorizeProduct($product, $request->user());

        $data = $request->validate([
            'lot_number' => ['required', 'string', 'max:255'],
            'buying_price' => ['required', 'numeric', 'min:0'],
            'quantity' => ['required', 'integer', 'min:1'],
            'received_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $lot = DB::transaction(function () use ($product, $data, $request) {
            $created = ProductLot::create([
            'product_id' => $product->id,
            'variation_id' => null,
            'lot_number' => $data['lot_number'],
            'buying_price' => $data['buying_price'],
            'selling_price' => $product->default_selling_price ?? 0,
            'quantity' => $data['quantity'],
            'quantity_remaining' => $data['quantity'],
            'received_at' => $data['received_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            ]);
            $this->recordReceipt($created, $request->user());
            app(RestockAlertService::class)->notify($product->id);
            return $created;
        });

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
            'quantity' => ['required', 'integer', 'min:1'],
            'received_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $lot = DB::transaction(function () use ($product, $variation, $data, $request) {
            $created = ProductLot::create([
            'product_id' => null,
            'variation_id' => $variation->id,
            'lot_number' => $data['lot_number'],
            'buying_price' => $data['buying_price'],
            'selling_price' => $variation->default_selling_price ?? $product->default_selling_price ?? 0,
            'quantity' => $data['quantity'],
            'quantity_remaining' => $data['quantity'],
            'received_at' => $data['received_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            ]);
            $this->recordReceipt($created, $request->user());
            app(RestockAlertService::class)->notify($product->id, $variation->id);
            return $created;
        });

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

    }

    private function recordReceipt(ProductLot $lot, $actor): void
    {
        ProductLotMovement::create([
            'product_lot_id' => $lot->id,
            'quantity_change' => $lot->quantity,
            'reason' => 'received',
            'actor_type' => $actor::class,
            'actor_id' => $actor->id,
            'meta' => ['lot_number' => $lot->lot_number],
        ]);
    }
}
