<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\SellerShippingRate;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class StoreShippingService
{
    /**
     * Build one delivery charge per store represented by the supplied lines.
     * Products without a seller belong to the NEXF platform store.
     *
     * @param array<int, array{product: Product, subtotal: float|int|string}> $lines
     * @return array{groups: array<int, array<string, mixed>>, total: float}
     */
    public function quote(array $lines, ShippingMethod $shippingMethod, array $selectedMethods = []): array
    {
        $groups = [];
        $sellerIds = collect($lines)->pluck('product.seller_id')->filter()->unique()->values();
        $sellerRates = SellerShippingRate::query()->whereIn('seller_id', $sellerIds)->get()->groupBy('seller_id');
        $customized = \App\Models\Seller::whereIn('id',$sellerIds)->pluck('delivery_options_customized','id');
        $methods = ShippingMethod::query()->active()->orderBy('sort_order')->orderBy('id')->get()->keyBy('id');

        foreach ($lines as $line) {
            $product = $line['product'];
            $sellerId = $product->seller_id === null ? null : (int) $product->seller_id;
            $key = $sellerId === null ? 'platform' : "seller:{$sellerId}";

            if (!isset($groups[$key])) {
                $selectionKey = $sellerId === null ? 'house' : (string) $sellerId;
                $custom = $sellerId === null ? (bool)\App\Models\Store::primary()?->delivery_options_customized : (bool)$customized->get($sellerId);
                $storeMethods = $methods->filter(fn($method)=>$custom ? ($sellerId === null ? $method->seller_id === null && $method->is_store_option : (int)$method->seller_id === $sellerId) : $method->seller_id === null && !$method->is_store_option);
                if ($storeMethods->isEmpty()) throw ValidationException::withMessages(['store_shipping_methods'=>['This store has no available delivery option.']]);
                if (array_key_exists($selectionKey,$selectedMethods)) $selectedMethod=$storeMethods->get((int)$selectedMethods[$selectionKey]);
                else $selectedMethod=$custom ? ($storeMethods->first(fn($method)=>Str::slug($method->name,'_') === Str::slug($shippingMethod->name,'_')) ?? $storeMethods->first()) : $storeMethods->get($shippingMethod->id);
                if (!$selectedMethod) throw ValidationException::withMessages(['store_shipping_methods'=>["Selected delivery option for {$selectionKey} is unavailable."]]);
                $rateMap = $sellerRates->get((string) $sellerId, collect())->keyBy('shipping_method_id');
                $options = $storeMethods->map(fn (ShippingMethod $method) => [
                    'shipping_method_id' => $method->id,
                    'code' => $method->code,
                    'name' => $method->name,
                    'charge' => (float) ($rateMap->get($method->id)?->charge ?? $method->charge),
                    'currency' => $method->currency,
                ])->values()->all();
                $groups[$key] = [
                    'seller_id' => $sellerId,
                    'shipping_method_id' => $selectedMethod->id,
                    'store_name' => $sellerId === null
                        ? (\App\Models\Store::primary()?->name ?? config('app.name'))
                        : ($product->seller?->store_name ?? 'Seller Store'),
                    'subtotal' => 0.0,
                    'shipping_method_code' => $selectedMethod->code,
                    'shipping_method_name' => $selectedMethod->name,
                    'shipping_charge' => (float) ($rateMap->get($selectedMethod->id)?->charge ?? $selectedMethod->charge),
                    'shipping_currency' => $selectedMethod->currency,
                    'shipping_options' => $options,
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
