<?php

namespace App\Services;

use App\Models\DeliveryGroup;

class SizeChartAccessService
{
    /**
     * Return the stores whose charts a seller may use, together with whether
     * the group also shares the house store's charts.
     *
     * @return array{seller_ids: array<int, int>, includes_house: bool}
     */
    public function accessForSeller(int $sellerId): array
    {
        $group = DeliveryGroup::query()->get()->first(
            fn (DeliveryGroup $group) => in_array($sellerId, array_map('intval', $group->seller_ids ?? []), true)
        );

        if ($group === null) {
            return ['seller_ids' => [$sellerId], 'includes_house' => false];
        }

        return [
            'seller_ids' => array_values(array_unique(array_map('intval', $group->seller_ids ?? []))),
            'includes_house' => (bool) $group->includes_house,
        ];
    }

    public function sellerMayUseChart(int $sellerId, ?int $chartSellerId): bool
    {
        $access = $this->accessForSeller($sellerId);

        return $chartSellerId === null
            ? $access['includes_house']
            : in_array((int) $chartSellerId, $access['seller_ids'], true);
    }
}
