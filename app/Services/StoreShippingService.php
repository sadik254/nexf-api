<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\SellerShippingRate;

class StoreShippingService
{
    /**
     * Build one delivery charge per store represented by the supplied lines.
     * Products without a seller belong to the NEXF platform store.
     *
     * @param array<int, array{product: Product, subtotal: float|int|string}> $lines
     * @return array{groups: array<int, array<string, mixed>>, total: float}
     */
    public function quote(array $lines, ShippingMethod $shippingMethod): array
    {
        $groups = [];
        $sellerIds = collect($lines)->pluck('product.seller_id')->filter()->unique()->values();
        $sellerRates = SellerShippingRate::query()->where('shipping_method_id', $shippingMethod->id)
            ->whereIn('seller_id', $sellerIds)->pluck('charge', 'seller_id');

        foreach ($lines as $line) {
            $product = $line['product'];
            $sellerId = $product->seller_id === null ? null : (int) $product->seller_id;
            $key = $sellerId === null ? 'platform' : "seller:{$sellerId}";

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'seller_id' => $sellerId,
                    'store_name' => $sellerId === null
                        ? 'NEXF Lifestyle'
                        : ($product->seller?->store_name ?? 'Seller Store'),
                    'subtotal' => 0.0,
                    'shipping_method_code' => $shippingMethod->code,
                    'shipping_method_name' => $shippingMethod->name,
                    'shipping_charge' => $sellerId !== null && $sellerRates->has($sellerId)
                        ? (float) $sellerRates[$sellerId] : (float) $shippingMethod->charge,
                    'shipping_currency' => $shippingMethod->currency,
                ];
            }

            $groups[$key]['subtotal'] = round(
                $groups[$key]['subtotal'] + (float) $line['subtotal'],
                2,
            );
        }

        $groups = array_values($groups);

        return [
            'groups' => $groups,
            'total' => round(array_sum(array_column($groups, 'shipping_charge')), 2),
        ];
    }
}
