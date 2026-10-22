<?php

namespace App\Services;

use App\Models\DeliveryGroup;
use App\Models\Product;
use App\Models\Seller;
use App\Models\SellerShippingRate;
use App\Models\ShippingMethod;
use App\Models\Store;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreShippingService
{
    /**
     * Produces one delivery charge for each shipping store. Stores in a shared
     * delivery group are represented by their group's main store and share one
     * charge, including when a linked store is the only store in the basket.
     *
     * @param array<int, array{product: Product, subtotal: float|int|string}> $lines
     * @return array{groups: array<int, array<string, mixed>>, total: float}
     */
    public function quote(array $lines, ShippingMethod $shippingMethod, array $selectedMethods = [], array $selectedDeliveryOptions = []): array
    {
        $deliveryGroups = DeliveryGroup::query()->get();
        $membership = [];
        foreach ($deliveryGroups as $group) {
            if ($group->includes_house) $membership['house'] = $group;
            foreach ($group->seller_ids ?? [] as $id) $membership['seller:' . (int) $id] = $group;
        }
        $sourceSellerIds = $deliveryGroups->pluck('ships_from_seller_id')->filter()->map(fn ($id) => (int) $id)
            ->merge(collect($lines)->pluck('product.seller_id')->filter()->map(fn ($id) => (int) $id))->unique()->values();
        $sellers = Seller::query()->whereIn('id', $sourceSellerIds)->get()->keyBy('id');
        $sellerRates = SellerShippingRate::query()->whereIn('seller_id', $sourceSellerIds)->get()->groupBy('seller_id');
        $methods = ShippingMethod::query()->active()->orderBy('sort_order')->orderBy('id')->get()->keyBy('id');
        $groups = [];

        foreach ($lines as $line) {
            $product = $line['product'];
            $productSellerId = $product->seller_id === null ? null : (int) $product->seller_id;
            $memberKey = $productSellerId === null ? 'house' : "seller:{$productSellerId}";
            $deliveryGroup = $membership[$memberKey] ?? null;
            $billingSellerId = $deliveryGroup ? ($deliveryGroup->ships_from_seller_id === null ? null : (int) $deliveryGroup->ships_from_seller_id) : $productSellerId;
            $key = $deliveryGroup ? "delivery-group:{$deliveryGroup->id}" : $memberKey;
            $selectionKey = $billingSellerId === null ? 'house' : (string) $billingSellerId;

            if (!isset($groups[$key])) {
                $memberNames = $deliveryGroup ? $this->memberNames($deliveryGroup, $sellers) : [$this->storeName($productSellerId, $product, $sellers)];
                // Product-level options are specific to a product. A shared group
                // always uses the main store's own delivery rates instead.
                $productOptions = [];
                if (!$deliveryGroup) {
                    foreach ($lines as $storeLine) {
                        $storeProduct = $storeLine['product'];
                        if (($storeProduct->seller_id === null ? 'house' : 'seller:' . (int) $storeProduct->seller_id) !== $memberKey) continue;
                        foreach ($storeProduct->shipping_options ?? [] as $option) {
                            $label = trim((string) ($option['label'] ?? ''));
                            if ($label === '') continue;
                            $optionKey = mb_strtolower($label);
                            if (!isset($productOptions[$optionKey]) || (float) $option['price'] > $productOptions[$optionKey]['charge']) {
                                $productOptions[$optionKey] = ['key'=>$optionKey, 'shipping_method_id'=>null, 'code'=>'product_option', 'name'=>$label, 'charge'=>(float)$option['price'], 'currency'=>'BDT'];
                            }
                        }
                    }
                }
                if ($productOptions) {
                    $options = array_values($productOptions);
                    $selectedKey = mb_strtolower(trim((string) ($selectedDeliveryOptions[$selectionKey] ?? $selectedDeliveryOptions[(string) $productSellerId] ?? '')));
                    $selected = collect($options)->firstWhere('key', $selectedKey) ?? $options[0];
                    $groups[$key] = $this->groupRow($billingSellerId, $memberNames, null, $selected, $options);
                } else {
                    $options = $this->rateOptions($billingSellerId, $sellerRates, $methods, $sellers);
                    if (!$options) throw ValidationException::withMessages(['store_shipping_methods'=>['This store has no available delivery option.']]);
                    $selectedId = $selectedMethods[$selectionKey] ?? $selectedMethods[(string) $productSellerId] ?? null;
                    $selected = $selectedId ? collect($options)->firstWhere('shipping_method_id', (int) $selectedId) : null;
                    if ($selectedId && !$selected) throw ValidationException::withMessages(['store_shipping_methods'=>["Selected delivery option for {$selectionKey} is unavailable."]]);
                    if (!$selected) {
                        $selected = collect($options)->first(fn ($option) => Str::slug($option['name'], '_') === Str::slug($shippingMethod->name, '_')) ?? $options[0];
                    }
                    if (!$selected) throw ValidationException::withMessages(['store_shipping_methods'=>["Selected delivery option for {$selectionKey} is unavailable."]]);
                    $groups[$key] = $this->groupRow($billingSellerId, $memberNames, $selected['shipping_method_id'], $selected, $options);
                }
            }
            $groups[$key]['subtotal'] = round($groups[$key]['subtotal'] + (float) $line['subtotal'], 2);
        }

        $groups = array_values($groups);
        return ['groups'=>$groups, 'total'=>round(array_sum(array_column($groups, 'shipping_charge')),2)];
    }

    private function rateOptions(?int $sellerId, $sellerRates, $methods, $sellers): array
    {
        $custom = $sellerId === null ? (bool) Store::primary()?->delivery_options_customized : (bool) $sellers->get($sellerId)?->delivery_options_customized;
        $storeMethods = $methods->filter(fn (ShippingMethod $method) => $custom
            ? ($sellerId === null ? $method->seller_id === null && $method->is_store_option : (int) $method->seller_id === $sellerId)
            : $method->seller_id === null && !$method->is_store_option);
        $rateMap = $sellerId === null ? collect() : $sellerRates->get((string) $sellerId, collect())->keyBy('shipping_method_id');
        return $storeMethods->map(fn (ShippingMethod $method) => ['shipping_method_id'=>$method->id,'code'=>$method->code,'name'=>$method->name,'charge'=>(float)($rateMap->get($method->id)?->charge ?? $method->charge),'currency'=>$method->currency])->values()->all();
    }

    private function groupRow(?int $sellerId, array $memberNames, ?int $methodId, array $selected, array $options): array
    {
        return ['seller_id'=>$sellerId,'shipping_method_id'=>$methodId,'store_name'=>implode(', ', $memberNames),'store_names'=>$memberNames,'subtotal'=>0.0,'shipping_method_code'=>$selected['code'],'shipping_method_name'=>$selected['name'],'shipping_charge'=>$selected['charge'],'shipping_currency'=>$selected['currency'],'shipping_options'=>$options];
    }
    private function memberNames(DeliveryGroup $group, $sellers): array {
        $names=[]; if($group->includes_house)$names[]=Store::primary()?->name??config('app.name');foreach($group->seller_ids??[] as $id)if($seller=$sellers->get((int)$id))$names[]=$seller->store_name;return $names;
    }
    private function storeName(?int $sellerId, Product $product, $sellers): string { return $sellerId===null?(Store::primary()?->name??config('app.name')):($sellers->get($sellerId)?->store_name ?? $product->seller?->store_name ?? 'Seller Store'); }
}
