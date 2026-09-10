<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductLot;
use App\Models\ProductLotMovement;
use App\Models\OrderItem;
use App\Models\ProductVariation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function previewProduct(Product $product, int $quantity): array
    {
        return $this->previewLots(productId: $product->id, variationId: null, quantityRequested: $quantity);
    }

    public function previewVariation(ProductVariation $variation, int $quantity): array
    {
        return $this->previewLots(productId: null, variationId: $variation->id, quantityRequested: $quantity);
    }
    public function restoreOrderItem(OrderItem $item, $actor, string $reason = 'order_cancellation'): void
    {
        foreach ($item->lot_allocations ?? [] as $allocation) {
            $lotId = (int) ($allocation['lot_id'] ?? 0);
            $quantity = (int) ($allocation['quantity'] ?? 0);

            if ($lotId <= 0 || $quantity <= 0) {
                throw ValidationException::withMessages([
                    'order' => ['Order inventory allocation data is invalid.'],
                ]);
            }

            $lot = ProductLot::query()->lockForUpdate()->find($lotId);
            if (!$lot) {
                throw ValidationException::withMessages([
                    'order' => ['Cannot cancel an order whose inventory lot no longer exists.'],
                ]);
            }

            $lot->increment('quantity_remaining', $quantity);

            ProductLotMovement::create([
                'product_lot_id' => $lot->id,
                'quantity_change' => $quantity,
                'reason' => $reason,
                'actor_type' => $actor::class,
                'actor_id' => $actor->id,
                'meta' => [
                    'order_id' => $item->order_id,
                    'order_item_id' => $item->id,
                    'restored_from_order_sale' => true,
                ],
            ]);
        }
    }

    public function consumeProduct(Product $product, int $quantity, $actor, string $reason = 'sale', array $meta = []): array
    {
        return $this->consumeLots(
            productId: $product->id,
            variationId: null,
            quantityRequested: $quantity,
            actor: $actor,
            reason: $reason,
            meta: $meta
        );
    }

    public function consumeVariation(ProductVariation $variation, int $quantity, $actor, string $reason = 'sale', array $meta = []): array
    {
        return $this->consumeLots(
            productId: null,
            variationId: $variation->id,
            quantityRequested: $quantity,
            actor: $actor,
            reason: $reason,
            meta: $meta
        );
    }

    private function consumeLots(?int $productId, ?int $variationId, int $quantityRequested, $actor, string $reason, array $meta): array
    {
        return DB::transaction(function () use ($productId, $variationId, $quantityRequested, $actor, $reason, $meta) {
            $actorType = $actor ? $actor::class : 'unknown';
            $actorId = $actor?->id ?? 0;

            $lots = ProductLot::query()
                ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
                ->when($variationId !== null, fn ($q) => $q->where('variation_id', $variationId))
                ->where('quantity_remaining', '>', 0)
                ->orderByRaw('received_at is null')
                ->orderBy('received_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $available = (int) $lots->sum('quantity_remaining');
            if ($available < $quantityRequested) {
                throw ValidationException::withMessages([
                    'quantity' => ["Insufficient stock. Requested {$quantityRequested}, available {$available}."],
                ]);
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

                $movementMeta = array_merge($meta, [
                    'unit_buying_price' => (string) $lot->buying_price,
                    'unit_selling_price' => (string) $lot->selling_price,
                ]);

                ProductLotMovement::create([
                    'product_lot_id' => $lot->id,
                    'quantity_change' => -$take,
                    'reason' => $reason,
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'meta' => $movementMeta,
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

            return [
                'requested_quantity' => $quantityRequested,
                'allocations' => $allocations,
                'totals' => $totals,
            ];
        });
    }

    private function previewLots(?int $productId, ?int $variationId, int $quantityRequested): array
    {
        $lots = ProductLot::query()
            ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
            ->when($variationId !== null, fn ($q) => $q->where('variation_id', $variationId))
            ->where('quantity_remaining', '>', 0)
            ->orderByRaw('received_at is null')->orderBy('received_at')->orderBy('id')->get();

        $available = (int) $lots->sum('quantity_remaining');
        if ($available < $quantityRequested) {
            throw ValidationException::withMessages(['quantity' => ["Insufficient stock. Requested {$quantityRequested}, available {$available}."]]);
        }

        $remaining = $quantityRequested;
        $cost = 0.0;
        $revenue = 0.0;
        foreach ($lots as $lot) {
            $take = min($remaining, (int) $lot->quantity_remaining);
            $cost = round($cost + ((float) $lot->buying_price * $take), 2);
            $revenue = round($revenue + ((float) $lot->selling_price * $take), 2);
            $remaining -= $take;
            if ($remaining === 0) break;
        }

        return ['quantity' => $quantityRequested, 'available_quantity' => $available, 'cost' => $cost, 'subtotal' => $revenue];
    }
}
