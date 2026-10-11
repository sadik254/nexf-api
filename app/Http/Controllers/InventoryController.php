<?php

namespace App\Http\Controllers;

use App\Models\Admin;
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
        $scope = $request->validate(['scope' => ['sometimes', 'in:house,all']])['scope'] ?? 'house';
        $query = Product::query()->when($scope !== 'all', fn ($q) => $q->whereNull('seller_id'));
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
        return $query->withSum('lots as available_quantity', 'quantity_remaining')
            ->with([
                'lots:id,product_id,lot_number,buying_price,selling_price,quantity,quantity_remaining,received_at,expires_at',
                'variations' => fn ($variations) => $variations->withSum('lots as available_quantity', 'quantity_remaining')->with('lots:id,variation_id,lot_number,buying_price,selling_price,quantity,quantity_remaining,received_at,expires_at'),
            ])
            ->latest()
            ->get()
            ->flatMap(function (Product $product) {
                if ($product->product_type === 'simple') return [[
                    'product_id' => $product->id,
                    'variation_id' => null,
                    'product_name' => $product->name,
                    'sku' => null,
                    'attributes' => null,
                    'is_active' => $product->status === 'active',
                    'available_quantity' => (int) ($product->available_quantity ?? 0),
                    'lots' => $product->lots->values(),
                ]];
                return $product->variations->map(fn ($variation) => [
                    'product_id' => $product->id,
                    'variation_id' => $variation->id,
                    'product_name' => $product->name,
                    'sku' => $variation->sku,
                    'attributes' => $variation->attributes,
                    'is_active' => $variation->is_active,
                    'available_quantity' => (int) ($variation->available_quantity ?? 0),
                    'lots' => $variation->lots->values(),
                ]);
            })->values()
            ->pipe(function ($rows) use ($request) {
                $perPage = max(1, min((int) $request->query('per_page', 25), 100));
                $page = max(1, (int) $request->query('page', 1));
                $paginator = new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]);
                return array_merge($paginator->toArray(), ['summary' => [
                    'available_units' => $rows->sum('available_quantity'),
                    'low_stock' => $rows->filter(fn ($row) => $row['available_quantity'] > 0 && $row['available_quantity'] <= 5)->count(),
                    'out_of_stock' => $rows->filter(fn ($row) => $row['available_quantity'] === 0)->count(),
                ]]);
            });
    }
}
