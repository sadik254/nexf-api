<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\ProductVariation;
use Illuminate\Validation\ValidationException;

/** Keeps product-combo definitions and resolves a cart combo into frozen pieces. */
class ProductBundleService
{
    public function replace(Product $product, array $bundles): void
    {
        $existing = $product->bundles()->with('items')->get()->keyBy('id');
        $kept = [];
        foreach (array_values($bundles) as $position => $row) {
            $id = isset($row['id']) ? (int) $row['id'] : null;
            $bundle = $id ? $existing->get($id) : null;
            if ($id && !$bundle) throw ValidationException::withMessages(['bundles' => ['A bundle does not belong to this product.']]);
            $mode = $row['mode'] ?? null;
            if (!in_array($mode, ['pick', 'fixed'], true)) throw ValidationException::withMessages(['bundles' => ['Choose Pick or Fixed for every combo.']]);
            $label = trim((string) ($row['label'] ?? ''));
            $size = (int) ($row['size'] ?? 0);
            $price = (float) ($row['price'] ?? 0);
            $compare = filled($row['compare_at_price'] ?? null) ? (float) $row['compare_at_price'] : null;
            if ($label === '' || mb_strlen($label) > 60) throw ValidationException::withMessages(['bundles' => ['Each combo needs a label of 60 characters or fewer.']]);
            if ($price <= 0 || ($compare !== null && $compare <= $price)) throw ValidationException::withMessages(['bundles' => ['A combo price must be positive and its compare-at price must be higher.']]);

            $items = array_values($row['items'] ?? []);
            if ($mode === 'pick') {
                $variationCount = $product->variations()->where('is_active', true)->count();
                if ($size < 2 || $size > $variationCount) throw ValidationException::withMessages(['bundles' => ['A pick combo must contain at least two, and no more than the available variations.']]);
                $items = [];
            } else {
                $total = array_sum(array_map(fn ($item) => (int) ($item['quantity'] ?? 0), $items));
                $minimum = $product->product_type === 'simple' ? 1 : 1;
                if ($total < $minimum || ($product->product_type === 'simple' && $total < 2)) throw ValidationException::withMessages(['bundles' => ['A fixed combo needs its included items.']]);
                if ($size !== $total) throw ValidationException::withMessages(['bundles' => ['The fixed combo size must equal the included quantity.']]);
                foreach ($items as $item) $this->assertAttributesConfigured($product, $item['attributes'] ?? null);
            }

            $bundle ??= new ProductBundle(['product_id' => $product->id]);
            $bundle->fill(['label' => $label, 'mode' => $mode, 'size' => $size, 'price' => $price, 'compare_at_price' => $compare, 'sort_order' => $position])->save();
            $bundle->items()->delete();
            if ($mode === 'fixed') foreach ($items as $item) {
                $bundle->items()->create(['attributes' => $item['attributes'] ?? null, 'quantity' => (int) $item['quantity']]);
            }
            $kept[] = $bundle->id;
        }
        $product->bundles()->whereNotIn('id', $kept)->delete();
    }

    public function pruneInvalidFixedItems(Product $product): void
    {
        $product->load('bundles.items');
        foreach ($product->bundles as $bundle) {
            if ($bundle->mode !== 'fixed') continue;
            foreach ($bundle->items as $item) {
                try { $this->variationForAttributes($product, $item->attributes); } catch (ValidationException) { $item->delete(); }
            }
            $total = (int) $bundle->fresh()->items()->sum('quantity');
            if ($total < 1 || ($product->product_type === 'simple' && $total < 2)) $bundle->delete();
            else $bundle->update(['size' => $total]);
        }
    }

    /** @return array{bundle: ProductBundle, components: array<int, array{variation: ?ProductVariation,quantity:int}>}|null */
    public function resolve(Product $product, array $line): ?array
    {
        if (empty($line['bundle_id'])) return null;
        $bundle = $product->bundles()->with('items')->find($line['bundle_id']);
        if (!$bundle) throw ValidationException::withMessages(['items' => ['The selected combo is unavailable.']]);
        $components = [];
        if ($bundle->mode === 'pick') {
            $rows = array_values($line['bundle_items'] ?? []);
            $activeCount = $product->variations()->where('is_active', true)->count();
            if (count($rows) < (int) $bundle->size) throw ValidationException::withMessages(['items' => ["Choose at least {$bundle->size} different variations for this combo."]]);
            if (count($rows) > $activeCount) throw ValidationException::withMessages(['items' => ['Choose no more than the available variations for this combo.']]);
            $seen = [];
            foreach ($rows as $row) {
                $id = (int) ($row['variation_id'] ?? 0);
                if ($id < 1 || (int) ($row['quantity'] ?? 0) !== 1 || isset($seen[$id])) throw ValidationException::withMessages(['items' => ['Each variation in a pick combo must be selected once.']]);
                $variation = $product->variations()->where('is_active', true)->find($id);
                if (!$variation) throw ValidationException::withMessages(['items' => ['A selected combo variation is unavailable.']]);
                $seen[$id] = true;
                $components[] = compact('variation') + ['quantity' => 1];
            }
        } else {
            foreach ($bundle->items as $item) {
                $components[] = ['variation' => $this->variationForAttributes($product, $item->attributes), 'quantity' => (int) $item->quantity];
            }
        }
        $pieceCount = array_sum(array_map(fn (array $component) => (int) $component['quantity'], $components));
        $unitPrice = $bundle->mode === 'pick'
            ? round(((float) $bundle->price / max(1, (int) $bundle->size)) * $pieceCount, 2)
            : (float) $bundle->price;

        return compact('bundle', 'components', 'pieceCount', 'unitPrice') + [
            'label' => $bundle->mode === 'pick' ? "{$pieceCount} pcs" : $bundle->label,
        ];
    }

    private function variationForAttributes(Product $product, ?array $attributes): ?ProductVariation
    {
        $this->assertAttributesConfigured($product, $attributes);
        if ($product->product_type === 'simple') return null;
        return $product->variations()->where('is_active', true)->get()->first(function (ProductVariation $variation) use ($attributes) {
            $candidate = $variation->attributes ?? []; ksort($candidate); return $candidate === $attributes;
        }) ?? throw ValidationException::withMessages(['bundles' => ['A combo item no longer has a purchasable variation.']]);
    }

    private function assertAttributesConfigured(Product $product, ?array $attributes): void
    {
        if ($product->product_type === 'simple') {
            if ($attributes !== null && $attributes !== []) throw ValidationException::withMessages(['bundles' => ['Simple-product combo items cannot have options.']]);
            return;
        }
        if (!$attributes) throw ValidationException::withMessages(['bundles' => ['Each variable-product combo item needs its option values.']]);
        $allowed = [];
        foreach ($product->option_groups ?? [] as $group) $allowed[(string) ($group['key'] ?? '')] = collect($group['values'] ?? [])->pluck('value')->map(fn ($v) => (string) $v)->all();
        ksort($attributes); $keys = array_keys($attributes); $expected = array_keys($allowed); sort($keys); sort($expected);
        if ($keys !== $expected) throw ValidationException::withMessages(['bundles' => ['A combo item must use one value from every product option.']]);
        foreach ($attributes as $key => $value) if (!in_array((string) $value, $allowed[$key] ?? [], true)) throw ValidationException::withMessages(['bundles' => ['A combo item contains an unavailable option.']]);
    }
}
