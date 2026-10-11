<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\ProductLotMovement;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class InventoryController extends Controller
{
    public function historyAdmin(Request $request): JsonResponse
    {
        abort_unless($request->user() instanceof Admin, 403);
        $scope = $request->validate(['scope' => ['sometimes', 'in:house,all']])['scope'] ?? 'house';
        return response()->json($this->history($request, null, $scope === 'all'));
    }

    public function historySeller(Request $request): JsonResponse
    {
        $seller = $request->user();
        abort_unless($seller instanceof Seller, 403);
        return response()->json($this->history($request, $seller->id));
    }

    private function history(Request $request, ?int $sellerId, bool $allStores = false): LengthAwarePaginator
    {
        $filters = $request->validate([
            'product_id' => ['sometimes', 'integer', 'min:1'],
            'variation_id' => ['sometimes', 'integer', 'min:1'],
            'reason' => ['sometimes', 'string', 'max:80'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        return ProductLotMovement::query()
            ->whereHas('lot', function ($lots) use ($sellerId, $filters, $allStores) {
                $lots->where(function ($query) use ($sellerId, $filters, $allStores) {
                    $query->whereHas('product', fn ($product) => $product
                        ->when(!$allStores, fn ($q) => $q->where('seller_id', $sellerId))
                        ->when(isset($filters['product_id']), fn ($q) => $q->whereKey($filters['product_id'])))
                        ->orWhereHas('variation.product', fn ($product) => $product
                            ->when(!$allStores, fn ($q) => $q->where('seller_id', $sellerId))
                            ->when(isset($filters['product_id']), fn ($q) => $q->whereKey($filters['product_id'])));
                })->when(isset($filters['variation_id']), fn ($q) => $q->where('variation_id', $filters['variation_id']));
            })
            ->when(isset($filters['reason']), fn ($q) => $q->where('reason', $filters['reason']))
            ->with(['lot.product:id,name', 'lot.variation:id,product_id,sku,attributes', 'lot.variation.product:id,name'])
            ->latest()->paginate($filters['per_page'] ?? 25);
    }
    public function indexAdmin(Request $request): JsonResponse
    {
        abort_unless($request->user() instanceof Admin, 403);
        $filters = $request->validate([
            'scope' => ['sometimes', 'in:house,all'],
            'seller_id' => ['sometimes', 'regex:/^(house|[1-9][0-9]*)$/'],
        ]);
        $scope = $filters['scope'] ?? 'all';
        $query = Product::query()
            ->when($scope !== 'all' || ($filters['seller_id'] ?? null) === 'house', fn ($q) => $q->whereNull('seller_id'))
            ->when(isset($filters['seller_id']) && $filters['seller_id'] !== 'house', fn ($q) => $q->where('seller_id', $filters['seller_id']));
        return response()->json($this->rows($query, $request));
    }

    public function indexSeller(Request $request): JsonResponse
    {
        $seller = $request->user();
        abort_unless($seller instanceof Seller, 403);
        return response()->json($this->rows(Product::query()->where('seller_id', $seller->id), $request));
    }

    private function rows($query, Request $request): array
    {
        $filters = $request->validate([
            'stock' => ['sometimes', 'in:all,low,out,ok,untracked'],
            'search' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $committed = OrderItem::query()
            ->whereIn('fulfillment_status', ['pending', 'confirmed'])
            ->whereHas('order', fn ($orders) => $orders->whereIn('status', ['pending', 'confirmed']))
            ->selectRaw('product_id, variation_id, SUM(quantity) as quantity, SUM(unit_buying_price * quantity) as cost_value')
            ->groupBy('product_id', 'variation_id')->get()
            ->keyBy(fn ($row) => $row->product_id . ':' . ($row->variation_id ?? 'simple'));
        return $query->withSum('lots as available_quantity', 'quantity_remaining')
            ->with([
                'brand:id,name',
                'seller:id,store_name',
                'lots:id,product_id,lot_number,buying_price,selling_price,quantity,quantity_remaining,received_at,expires_at',
                'variations' => fn ($variations) => $variations->withSum('lots as available_quantity', 'quantity_remaining')->with('lots:id,variation_id,lot_number,buying_price,selling_price,quantity,quantity_remaining,received_at,expires_at'),
            ])
            ->latest()
            ->get()
            ->flatMap(function (Product $product) use ($committed) {
                $makeRow = function ($variation = null) use ($product, $committed) {
                    $variationId = $variation?->id;
                    $lots = $variation ? $variation->lots : $product->lots->whereNull('variation_id');
                    $available = (int) ($variation ? ($variation->available_quantity ?? 0) : ($product->available_quantity ?? 0));
                    $held = $committed->get($product->id . ':' . ($variationId ?? 'simple'));
                    $committedQuantity = (int) ($held->quantity ?? 0);
                    $costValue = (float) $lots->sum(fn ($lot) => (int) $lot->quantity_remaining * (float) $lot->buying_price)
                        + (float) ($held->cost_value ?? 0);
                    $unitPrice = (float) ($variation?->default_selling_price ?? $product->default_selling_price ?? 0);
                    $onHand = $available + $committedQuantity;
                    return [
                        'product_id' => $product->id,
                        'seller_id' => $product->seller_id,
                        'seller_name' => $product->seller?->store_name,
                        'variation_id' => $variationId,
                        'product_name' => $product->name,
                        'brand' => $product->brand?->name,
                        'thumbnail' => $product->image_variants['thumbnail']['thumb'] ?? $product->thumbnail,
                        'sku' => $variation?->sku ?? $product->sku,
                        'attributes' => $variation?->attributes,
                        'is_active' => $variation ? $variation->is_active : $product->status === 'active',
                        'available_quantity' => $available,
                        'committed_quantity' => $committedQuantity,
                        'on_hand_quantity' => $onHand,
                        'cost_value' => round($costValue, 2),
                        'retail_value' => round($onHand * $unitPrice, 2),
                        'has_cost' => $lots->isNotEmpty() || $held !== null,
                        'lots' => $lots->values(),
                    ];
                };
                if ($product->product_type === 'simple') return [$makeRow()];
                return $product->variations->map(fn ($variation) => $makeRow($variation));
            })->values()
            ->pipe(function ($rows) use ($request, $filters) {
                $level = fn ($row) => count($row['lots']) === 0
                    ? 'untracked'
                    : ($row['available_quantity'] <= 0 ? 'out' : ($row['available_quantity'] <= 5 ? 'low' : 'ok'));
                $counts = ['all' => $rows->count(), 'low' => 0, 'out' => 0, 'ok' => 0, 'untracked' => 0];
                foreach ($rows as $row) $counts[$level($row)]++;
                $visible = $rows
                    ->when(($filters['stock'] ?? 'all') !== 'all', fn ($items) => $items->filter(fn ($row) => $level($row) === $filters['stock']))
                    ->when(isset($filters['search']) && trim($filters['search']) !== '', function ($items) use ($filters) {
                        $needle = mb_strtolower(trim($filters['search']));
                        return $items->filter(function ($row) use ($needle) {
                            $attributes = is_array($row['attributes']) ? implode(' ', array_values($row['attributes'])) : '';
                            return str_contains(mb_strtolower(implode(' ', array_filter([$row['product_name'], $row['brand'], $row['sku'], $row['seller_name'], $attributes]))), $needle);
                        });
                    })->values();
                $perPage = $filters['per_page'] ?? 25;
                $page = $filters['page'] ?? 1;
                $paginator = new LengthAwarePaginator($visible->forPage($page, $perPage)->values(), $visible->count(), $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]);
                return array_merge($paginator->toArray(), ['summary' => [
                    'units_on_hand' => $rows->sum('on_hand_quantity'),
                    'available_units' => $rows->sum('available_quantity'),
                    'committed_units' => $rows->sum('committed_quantity'),
                    'stock_value_at_cost' => round($rows->sum('cost_value'), 2),
                    'stock_value_at_retail' => round($rows->sum('retail_value'), 2),
                    'uncosted_skus' => $rows->filter(fn ($row) => $row['on_hand_quantity'] > 0 && !$row['has_cost'])->count(),
                    'low_stock' => $counts['low'],
                    'out_of_stock' => $counts['out'],
                    'untracked' => $counts['untracked'],
                ], 'stock_counts' => $counts]);
            });
    }
}
