<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Seller;
use App\Models\Store;
use App\Models\Review;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreProductController extends Controller
{
    public function indexAll(Request $request): JsonResponse
    {
        $query = $this->baseStoreQuery();

        return $this->paginateProducts($query, $request);
    }

    public function show(string $product): JsonResponse
    {
        $product = $this->baseStoreQuery()
            ->where('slug', $product)
            ->firstOrFail();

        return response()->json($this->transformProduct($product));
    }

    public function reviews(string $product, Request $request): JsonResponse
    {
        $product = Product::where('slug', $product)->where('status', 'active')->firstOrFail();
        $perPage = max(1, min((int) $request->query('per_page', 10), 50));
        return response()->json($product->reviews()->where('status', 'approved')->with('customer:id,name')->latest()->paginate($perPage)->through(fn ($review) => ['id'=>$review->id,'rating'=>$review->rating,'comment'=>$review->comment,'customer_name'=>$review->customer?->name,'created_at'=>$review->created_at?->toDateString()]));
    }

    public function indexByCategory(ProductCategory $category, Request $request): JsonResponse
    {
        $query = $this->baseStoreQuery()
            ->where('category_id', $category->id);

        return $this->paginateProducts($query, $request);
    }

    public function indexBySeller(Seller $seller, Request $request): JsonResponse
    {
        $query = Product::query()
            ->where('status', 'active')
            ->where('seller_id', $seller->id)
            ->whereHas('seller', function (Builder $q) {
                $q->where('status', 'approved')->where('is_active', true);
            });
        if ($request->filled('exclude')) $query->where('slug', '!=', $request->query('exclude'));
        if ($request->filled('limit')) $request->merge(['per_page' => min((int) $request->query('limit'), 100)]);

        $this->applyCommonEagerLoads($query);
        $this->applySearchFilters($query, $request);
        $this->applyPriceStockSubselects($query);

        return $this->paginateProducts($query, $request);
    }

    public function indexAdminStore(Request $request): JsonResponse
    {
        $query = Product::query()
            ->where('status', 'active')
            ->whereNull('seller_id');
        if ($request->filled('exclude')) $query->where('slug', '!=', $request->query('exclude'));
        if ($request->filled('limit')) $request->merge(['per_page' => min((int) $request->query('limit'), 100)]);

        $this->applyCommonEagerLoads($query);
        $this->applySearchFilters($query, $request);
        $this->applyPriceStockSubselects($query);

        return $this->paginateProducts($query, $request);
    }

    private function baseStoreQuery(): Builder
    {
        $query = Product::query()
            ->where('status', 'active')
            ->where(function (Builder $q) {
                $q->whereNull('seller_id')
                    ->orWhereHas('seller', function (Builder $sq) {
                        $sq->where('status', 'approved')->where('is_active', true);
                    });
            });

        $this->applyCommonEagerLoads($query);
        $this->applyPriceStockSubselects($query);

        return $query;
    }

    private function paginateProducts(Builder $query, Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        $products = $query->latest()->paginate($perPage);

        $products->getCollection()->transform(function (Product $product) {
            return $this->transformProduct($product);
        });

        return response()->json($products);
    }

    private function transformProduct(Product $product): array
    {
        $platformStore = Store::primary();
        $store = $product->seller_id === null
            ? [
                'type' => 'admin',
                'name' => $platformStore?->name ?? config('app.name'),
                'slug' => 'admin',
                'logo' => $platformStore?->logo,
                'image' => null,
            ]
            : [
                'type' => 'seller',
                'id' => $product->seller?->id,
                'name' => $product->seller?->store_name,
                'slug' => $product->seller?->store_slug,
                'logo' => $product->seller?->store_logo,
                'image' => $product->seller?->store_image,
                'location' => $product->seller?->city ? trim(($product->seller->city ?? '') . ($product->seller->country ? ', '.$product->seller->country : '')) : null,
                'joined_at' => $product->seller?->created_at?->toDateString(),
                'rating_summary' => ['average'=>null,'review_count'=>0,'sold_count'=>(int)$product->seller?->orderItems()->where('fulfillment_status','delivered')->whereHas('order', fn($q)=>$q->whereIn('status',['delivered','completed']))->sum('quantity')],
                'performance' => ['positive_rating_percentage'=>$product->seller?->positive_rating_percentage,'on_time_shipping_percentage'=>$product->seller?->on_time_shipping_percentage,'chat_response_percentage'=>$product->seller?->chat_response_percentage],
            ];

        $availableQuantity = (int) ($product->available_quantity ?? 0);
        $currentPrice = $product->current_selling_price !== null ? (string) $product->current_selling_price : null;
        $compareAt = $product->compare_at_price !== null ? (string) $product->compare_at_price : null;
        $discountAmount = $compareAt !== null && $currentPrice !== null && (float) $compareAt > (float) $currentPrice ? number_format((float) $compareAt - (float) $currentPrice, 2, '.', '') : null;
        $discount = $discountAmount === null ? null : ['amount' => $discountAmount, 'percentage' => (int) round(((float) $discountAmount / (float) $compareAt) * 100)];
        $approvedReviews = $product->reviews()->where('status', 'approved');
        $ratingCount = (int) (clone $approvedReviews)->count();
        $averageRating = $ratingCount ? round((float) (clone $approvedReviews)->avg('rating'), 2) : null;
        $soldCount = (int) $product->orderItems()->where('fulfillment_status', 'delivered')->whereHas('order', fn ($q) => $q->whereIn('status', ['delivered','completed']))->sum('quantity');

        $variations = [];
        $priceFrom = null;
        $priceTo = null;

        if ($product->product_type === 'variable') {
            $variations = $product->variations->map(function ($v) {
                return [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'attributes' => $v->attributes,
                    'current_selling_price' => $v->current_selling_price !== null ? (string) $v->current_selling_price : null,
                    'available_quantity' => (int) ($v->available_quantity ?? 0),
                    'in_stock' => ((int) ($v->available_quantity ?? 0)) > 0,
                ];
            })->values()->all();

            $prices = collect($variations)
                ->filter(fn ($v) => $v['current_selling_price'] !== null)
                ->map(fn ($v) => (float) $v['current_selling_price'])
                ->values();

            if ($prices->isNotEmpty()) {
                $priceFrom = (string) $prices->min();
                $priceTo = (string) $prices->max();
            }

            $availableQuantity = (int) collect($variations)->sum('available_quantity');
            $currentPrice = $priceFrom;
        }

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'specifications' => $product->specifications ?? [],
            'product_type' => $product->product_type,
            'status' => $product->status,
            'thumbnail' => $product->thumbnail,
            'gallery' => $product->gallery,
            'image_variants' => $product->image_variants,
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ] : null,
            'store' => $store,
            'compare_at_price' => $compareAt,
            'discount' => $discount,
            'rating_summary' => ['average' => $averageRating, 'review_count' => $ratingCount, 'sold_count' => $soldCount],
            'option_groups' => $this->optionGroups($product),
            'size_chart' => $product->sizeChart ? ['id'=>$product->sizeChart->id,'name'=>$product->sizeChart->name,'url'=>$product->sizeChart->url] : null,
            'current_selling_price' => $currentPrice,
            'price_from' => $priceFrom,
            'price_to' => $priceTo,
            'available_quantity' => $availableQuantity,
            'in_stock' => $availableQuantity > 0,
            'variations' => $variations,
        ];
    }

    private function optionGroups(Product $product): array
    {
        if ($product->option_groups) return $product->option_groups;
        $values = [];
        foreach ($product->variations as $variation) foreach (($variation->attributes ?? []) as $key => $value) $values[$key][(string) $value] = ['value'=>(string)$value,'label'=>(string)$value] + (strtolower($key) === 'color' ? ['swatch'=>null] : []);
        return collect($values)->map(fn ($items, $key) => ['key'=>$key,'label'=>ucwords(str_replace('_',' ', $key)),'display_type'=>strtolower($key)==='color'?'swatch':'button','values'=>array_values($items)])->values()->all();
    }

    private function applyCommonEagerLoads(Builder $query): void
    {
        $query->with([
            'category:id,name,slug',
            'seller:id,store_name,store_slug,store_logo,store_image,city,country,created_at,positive_rating_percentage,on_time_shipping_percentage,chat_response_percentage',
            'sizeChart:id,name,url',
            'variations' => function ($q) {
                $q->select(['id', 'product_id', 'sku', 'attributes'])
                    ->selectSub($this->variationCurrentPriceSubquery(), 'current_selling_price')
                    ->selectSub($this->variationAvailableQtySubquery(), 'available_quantity');
            },
        ]);
    }

    private function applySearchFilters(Builder $query, Request $request): void
    {
        $search = (string) $request->query('search', '');
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }
    }

    private function applyPriceStockSubselects(Builder $query): void
    {
        $query->select([
            'id',
            'seller_id',
            'category_id',
            'name',
            'slug',
            'description',
            'specifications',
            'product_type',
            'status',
            'thumbnail',
            'gallery',
            'compare_at_price', 'option_groups', 'size_chart_id',
        ])->selectSub($this->productCurrentPriceSubquery(), 'current_selling_price')
            ->selectSub($this->productAvailableQtySubquery(), 'available_quantity');
    }

    private function productCurrentPriceSubquery(): QueryBuilder
    {
        return DB::table('product_lots')
            ->select('selling_price')
            ->whereColumn('product_lots.product_id', 'products.id')
            ->where('quantity_remaining', '>', 0)
            ->orderByRaw('received_at is null')
            ->orderBy('received_at')
            ->orderBy('id')
            ->limit(1);
    }

    private function productAvailableQtySubquery(): QueryBuilder
    {
        return DB::table('product_lots')
            ->selectRaw('coalesce(sum(quantity_remaining), 0)')
            ->whereColumn('product_lots.product_id', 'products.id');
    }

    private function variationCurrentPriceSubquery(): QueryBuilder
    {
        return DB::table('product_lots')
            ->select('selling_price')
            ->whereColumn('product_lots.variation_id', 'product_variations.id')
            ->where('quantity_remaining', '>', 0)
            ->orderByRaw('received_at is null')
            ->orderBy('received_at')
            ->orderBy('id')
            ->limit(1);
    }

    private function variationAvailableQtySubquery(): QueryBuilder
    {
        return DB::table('product_lots')
            ->selectRaw('coalesce(sum(quantity_remaining), 0)')
            ->whereColumn('product_lots.variation_id', 'product_variations.id');
    }
}
