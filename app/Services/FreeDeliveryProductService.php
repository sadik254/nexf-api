<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Collection;

/**
 * Determines whether a storefront product can advertise an automatic
 * free-delivery offer when bought by itself. Checkout remains authoritative
 * because a real cart can contain other items in the same parcel.
 */
class FreeDeliveryProductService
{
    /** @var Collection<int, Coupon>|null */
    private ?Collection $coupons = null;

    /** @var array<int, array<int, int>> */
    private array $categoryDescendants = [];

    public function appliesTo(Product $product): bool
    {
        $price = $this->sellingPrice($product);
        if ($price === null) return false;

        foreach ($this->coupons() as $coupon) {
            if ($coupon->seller_id !== null && (int) $coupon->seller_id !== (int) $product->seller_id) continue;
            if ($coupon->minimum_quantity !== null && (int) $coupon->minimum_quantity > 1) continue;
            if ($coupon->minimum_order_amount !== null && $price < (float) $coupon->minimum_order_amount) continue;
            if ($this->matchesProduct($coupon, $product)) return true;
        }

        return false;
    }

    /** @return Collection<int, Coupon> */
    private function coupons(): Collection
    {
        if ($this->coupons !== null) return $this->coupons;

        return $this->coupons = Coupon::query()
            ->where('is_automatic', true)
            ->where(fn ($query) => $query->where('discount_kind', 'shipping')->orWhere('applies_to', 'shipping'))
            ->orderBy('id')
            ->get()
            ->filter(fn (Coupon $coupon) => $coupon->unusableReason() === null)
            ->values();
    }

    private function matchesProduct(Coupon $coupon, Product $product): bool
    {
        $productIds = array_map('intval', $coupon->eligible_product_ids ?? []);
        $categoryIds = $this->descendantCategoryIds($coupon->eligible_category_ids ?? []);
        $collectionIds = array_map('intval', $coupon->eligible_collection_ids ?? []);
        if ($productIds === [] && $categoryIds === [] && $collectionIds === []) return true;

        if (in_array((int) $product->id, $productIds, true)) return true;
        $productCategoryIds = [(int) $product->category_id, ...$product->categoryPlacements->pluck('category_id')->map(fn ($id) => (int) $id)->all()];
        if ($categoryIds !== [] && array_intersect($productCategoryIds, $categoryIds) !== []) return true;

        return $collectionIds !== [] && $product->collections->contains(fn ($collection) => in_array((int) $collection->id, $collectionIds, true));
    }

    /** @param array<int, mixed> $categoryIds @return array<int, int> */
    private function descendantCategoryIds(array $categoryIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));
        if ($ids === []) return [];
        $key = implode(',', $ids);
        if (isset($this->categoryDescendants[$key])) return $this->categoryDescendants[$key];

        $all = $ids;
        $frontier = $ids;
        while ($frontier !== []) {
            $children = ProductCategory::query()->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $frontier = array_values(array_diff($children, $all));
            $all = array_values(array_unique([...$all, ...$frontier]));
        }

        return $this->categoryDescendants[$key] = $all;
    }

    private function sellingPrice(Product $product): ?float
    {
        if ($product->product_type !== 'variable') {
            return $product->default_selling_price === null ? null : (float) $product->default_selling_price;
        }

        $prices = $product->variations->pluck('default_selling_price')->filter(fn ($price) => $price !== null)->map(fn ($price) => (float) $price);
        return $prices->isEmpty() ? null : $prices->min();
    }
}
