<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\ProductLotMovement;
use App\Models\ProductVariation;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductConsumptionController extends Controller
{
    public function consumeProduct(Product $product, Request $request): JsonResponse
    {
        $actor = $request->user();
        $this->authorizeProduct($product, $actor);

        if ($product->product_type !== 'simple') {
            return response()->json(['message' => 'Use variation consumption for variable products.'], 422);
        }

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        return $this->consumeLots(
            productId: $product->id,
            variationId: null,
            quantityRequested: (int) $data['quantity'],
            actor: $actor
        );
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

        return $this->consumeLots(
            productId: null,
            variationId: $variation->id,
            quantityRequested: (int) $data['quantity'],
            actor: $actor
        );
    }

    private function consumeLots(?int $productId, ?int $variationId, int $quantityRequested, $actor): JsonResponse
    {
        $actorType = $actor ? $actor::class : 'unknown';
        $actorId = $actor?->id ?? 0;

        $result = DB::transaction(function () use ($productId, $variationId, $quantityRequested, $actorType, $actorId) {
            $lotsQuery = ProductLot::query()
                ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
                ->when($variationId !== null, fn ($q) => $q->where('variation_id', $variationId))
                ->where('quantity_remaining', '>', 0)
                ->orderByRaw('received_at is null')
                ->orderBy('received_at')
                ->orderBy('id')
                ->lockForUpdate();

            $lots = $lotsQuery->get();

            $available = (int) $lots->sum('quantity_remaining');
            if ($available < $quantityRequested) {
                return response()->json([
                    'message' => 'Insufficient stock.',
                    'requested' => $quantityRequested,
                    'available' => $available,
                ], 422);
            }

            $remaining = $quantityRequested;
            $allocations = [];
            $totals = [
                'quantity' => 0,
                'cost' => 0.0,
                'revenue' => 0.0,
                'profit' => 0.0,
            ];

            foreach ($lots as $lot) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min($remaining, (int) $lot->quantity_remaining);
                if ($take <= 0) {
                    continue;
                }

                $lot->quantity_remaining = (int) $lot->quantity_remaining - $take;
                $lot->save();

                ProductLotMovement::create([
                    'product_lot_id' => $lot->id,
                    'quantity_change' => -$take,
                    'reason' => 'sale',
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'meta' => [
                        'unit_buying_price' => (string) $lot->buying_price,
                        'unit_selling_price' => (string) $lot->selling_price,
                    ],
                ]);

                $lineCost = round(((float) $lot->buying_price) * $take, 2);
                $lineRevenue = round(((float) $lot->selling_price) * $take, 2);
                $lineProfit = round($lineRevenue - $lineCost, 2);

                $allocations[] = [
                    'lot_id' => $lot->id,
                    'lot_number' => $lot->lot_number,
                    'quantity' => $take,
                    'unit_buying_price' => (string) $lot->buying_price,
                    'unit_selling_price' => (string) $lot->selling_price,
                    'cost' => $lineCost,
                    'revenue' => $lineRevenue,
                    'profit' => $lineProfit,
                    'quantity_remaining_after' => (int) $lot->quantity_remaining,
                ];

                $totals['quantity'] += $take;
                $totals['cost'] = round($totals['cost'] + $lineCost, 2);
                $totals['revenue'] = round($totals['revenue'] + $lineRevenue, 2);
                $totals['profit'] = round($totals['profit'] + $lineProfit, 2);

                $remaining -= $take;
            }

            return response()->json([
                'message' => 'Consumed successfully.',
                'requested_quantity' => $quantityRequested,
                'allocations' => $allocations,
                'totals' => $totals,
            ]);
        });

        return $result;
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
