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
use Illuminate\Support\Str;

class StoreProductController extends Controller
{
    public function indexAll(Request $request): JsonResponse
    {
        $query = $this->baseStoreQuery();
        $this->applySearchFilters($query, $request);
        $this->applyHomepageFilter($query, $request);
        if ($request->filled('collection')) {
            $value = (string) $request->query('collection');
            $id = ctype_digit($value) ? (int) $value : \App\Models\ProductCollection::query()->get(['id', 'name'])
                ->first(fn ($collection) => Str::slug($collection->name) === $value)?->id;
            $query->whereIn('products.id', DB::table('collection_product')->select('product_id')
                ->where('product_collection_id', $id ?? -1));
        }

        return $this->paginateProducts($query, $request);
    }

    public function stores(): JsonResponse
    {
        return response()->json(Seller::where('status', 'approved')->where('is_active', true)->orderByDesc('is_featured')->orderBy('store_name')->get()->map(fn ($seller) => $this->transformSellerStore($seller)));
    }

    public function storeProfile(string $slug): JsonResponse
    {
        $seller = Seller::where('store_slug', $slug)->where('status', 'approved')->where('is_active', true)->firstOrFail();
        return response()->json($this->transformSellerStore($seller));
    }

    private function transformSellerStore(Seller $seller): array
    {
        $reviews = Review::where('status', 'approved')->whereHas('product', fn ($products) => $products->where('seller_id', $seller->id)->where('status', 'active'));
        $count = (clone $reviews)->count();
        return [
            'type' => 'seller', 'id' => $seller->id, 'name' => $seller->store_name,
            'slug' => $seller->store_slug, 'logo' => $seller->store_logo, 'image' => $seller->store_image,
            'is_featured' => (bool) $seller->is_featured,
            'location' => $seller->city ? trim($seller->city.($seller->country ? ', '.$seller->country : '')) : null,
            'joined_at' => $seller->created_at?->toDateString(),
            'rating_summary' => ['average' => $count ? round((float) (clone $reviews)->avg('rating'), 2) : null, 'review_count' => $count,
                'sold_count' => (int) $seller->orderItems()->where('fulfillment_status', 'delivered')->whereHas('order', fn ($orders) => $orders->whereIn('status', ['delivered', 'completed']))->sum('quantity')],
            'performance' => ['positive_rating_percentage' => $seller->positive_rating_percentage,
                'on_time_shipping_percentage' => $seller->on_time_shipping_percentage, 'chat_response_percentage' => $seller->chat_response_percentage],
        ];
    }

    public function marketplaceStats(): JsonResponse
    {
        return response()->json([
            'sellers' => Seller::where('status', 'approved')->where('is_active', true)->count(),
            'products' => $this->baseStoreQuery()->count(),
            'customers' => \App\Models\Customer::count(),
            // The order schema only retains free-form addresses; coverage cannot be inferred reliably.
            'districts' => null,
        ]);
    }

    public function homepageProducts(Request $request, string $section): JsonResponse
    {
        $sections = \App\Models\SiteSetting::find('home_layout')?->payload ?? config('site-content.home_layout');
        $settings = collect($sections)->firstWhere('id', $section);
        abort_unless($settings && $settings['enabled'], 404);
        $tab = $request->validate(['tab' => ['sometimes', 'in:top,new']])['tab'] ?? 'top';
        return $this->resolveHomepageProducts($settings, $tab);
    }

    public function previewHomepageProducts(Request $request): JsonResponse
    {
        abort_unless($request->user() instanceof \App\Models\Admin && $request->user()->role === 'super_admin', 403);
        $data = $request->validate([
            'type' => ['required', 'in:trending,collection,featured'],
            'tab' => ['sometimes', 'in:top,new'], 'topSource' => ['sometimes', 'in:auto,picked'], 'newSource' => ['sometimes', 'in:auto,picked'],
            'showNewArrivals' => ['sometimes', 'boolean'], 'source' => ['sometimes', 'in:products,collection,category,tag'],
            'sourceValue' => ['sometimes', 'nullable', 'string', 'max:255'], 'collection_id' => ['sometimes', 'nullable', 'integer'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'productIds' => ['sometimes', 'array', 'max:100'], 'productIds.*' => ['integer'],
            'topProductIds' => ['sometimes', 'array', 'max:100'], 'topProductIds.*' => ['integer'],
            'newProductIds' => ['sometimes', 'array', 'max:100'], 'newProductIds.*' => ['integer'],
        ]);
        return $this->resolveHomepageProducts($data, $data['tab'] ?? 'top');
    }

    private function resolveHomepageProducts(array $settings, string $tab): JsonResponse
    {
        $query = $this->baseStoreQuery();
        $ids = null;
        if ($settings['type'] === 'trending') {
            if ($tab === 'new' && ($settings['showNewArrivals'] ?? true) === false) return response()->json([]);
            $source = $tab === 'new' ? ($settings['newSource'] ?? 'auto') : ($settings['topSource'] ?? 'auto');
            if ($source === 'picked') $ids = $settings[$tab === 'new' ? 'newProductIds' : 'topProductIds'] ?? [];
            elseif ($tab === 'top') $query->withSum(['orderItems as homepage_sales' => fn ($items) => $items->where('fulfillment_status', 'delivered')->whereHas('order', fn ($orders) => $orders->whereIn('status', ['delivered', 'completed']))], 'quantity')->orderByDesc('homepage_sales');
        } elseif ($settings['type'] === 'featured') {
            $ids = $settings['productIds'] ?? [];
        } elseif ($settings['type'] === 'collection') {
            $source = $settings['source'] ?? 'collection';
            $value = $settings['sourceValue'] ?? $settings['collection_id'] ?? null;
            if ($source === 'products') $ids = $settings['productIds'] ?? [];
            elseif ($source === 'category') $query->whereHas('category', fn ($category) => $category->where('slug', $value)->orWhereHas('parent', fn ($parent) => $parent->where('slug', $value)));
            elseif ($source === 'tag') $query->whereHas('tags', fn ($tags) => $tags->where('slug', $value));
            else $query->whereIn('products.id', DB::table('collection_product')->select('product_id')->where('product_collection_id', $value));
        } else abort(422, 'This block does not contain products.');
        if ($ids !== null) {
            $query->whereIn('products.id', $ids);
            if ($ids) {
                $cases = collect($ids)->map(fn ($id, $position) => 'WHEN '.(int) $id.' THEN '.(int) $position)->join(' ');
                $query->orderByRaw('CASE products.id '.$cases.' END');
            }
        } else $query->latest('products.created_at');
        return response()->json($query->limit($settings['limit'] ?? ($settings['type'] === 'trending' ? 10 : 12))->get()->map(fn ($product) => $this->transformProduct($product)));
    }

    public function testimonials(Request $request): JsonResponse
    {
        $request->validate(['ids' => ['sometimes', 'string', 'max:1000', 'regex:/^\d+(,\d+)*$/']]);
        $ids = $request->filled('ids') ? array_values(array_unique(array_map('intval', explode(',', $request->query('ids'))))) : null;
        $query = Review::query()->where('status', 'approved')->whereNotNull('comment')
            ->whereHas('product', fn ($q) => $q->where('status', 'active')->availableForSale()->where(fn ($visible) => $visible->whereNull('seller_id')->orWhereHas('seller', fn ($seller) => $seller->where('status', 'approved')->where('is_active', true))))
            ->with(['customer:id,name,profile_picture', 'product:id,name,slug']);
        if ($ids !== null) {
            $cases = collect($ids)->map(fn ($id, $position) => 'WHEN '.$id.' THEN '.$position)->join(' ');
            $query->whereIn('id', $ids)->orderByRaw('CASE reviews.id '.$cases.' END');
        } else $query->latest()->limit(12);
        $reviews = $query->get();
        return response()->json($reviews->map(fn ($review) => [
            'id' => $review->id, 'rating' => $review->rating, 'body' => $review->comment,
            'author' => $review->customer?->name ?? 'Customer', 'avatar' => $review->customer?->profile_picture,
            'purchased' => $review->product?->name, 'product_slug' => $review->product?->slug, 'verified' => $review->order_item_id !== null,
        ]));
    }

    public function show(string $product): JsonResponse
    {
        $product = $this->baseStoreQuery(true)
            ->where('slug', $product)
            ->firstOrFail();

        return response()->json($this->transformProduct($product));
    }

    public function reviews(string $product, Request $request): JsonResponse
    {
        $product = Product::where('slug', $product)->whereIn('status', ['active', 'unlisted'])->firstOrFail();
        $perPage = max(1, min((int) $request->query('per_page', 10), 50));
        return response()->json($product->reviews()->where('status', 'approved')->with(['customer:id,name,profile_picture','orderItem:id,variation_attributes'])->withCount(['likes','responseLikes'])->latest()->paginate($perPage)->through(fn ($review) => ['id'=>$review->id,'rating'=>$review->rating,'comment'=>$review->comment,'customer_name'=>$review->customer?->name,'avatar'=>$review->customer?->profile_picture,'images'=>$review->images??[],'videos'=>$review->videos??[],'likes'=>$review->likes_count,'verified'=>$review->order_item_id!==null,'purchased_variant'=>$review->orderItem?->variation_attributes,'media_details'=>$review->media_details,'seller_response_likes'=>$review->response_likes_count,'seller_response'=>$review->seller_response,'seller_responded_at'=>$review->seller_responded_at?->toDateString(),'created_at'=>$review->created_at?->toDateString()]));
    }

    public function indexByCategory(ProductCategory $category, Request $request): JsonResponse
    {
        $categoryIds = $category->children()->pluck('id')->prepend($category->id);
        $query = $this->baseStoreQuery()
            ->whereIn('category_id', $categoryIds);

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

    private function baseStoreQuery(bool $directLink = false): Builder
    {
        $query = Product::query()
            ->whereIn('status', $directLink ? ['active', 'unlisted'] : ['active'])->availableForSale()
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

        $products = $request->filled('homepage')
            ? $query->orderBy('homepage_sort_order')->latest()->paginate($perPage)
            : $query->latest()->paginate($perPage);

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
            : $this->transformSellerStore($product->seller);

        $availableQuantity = (int) ($product->available_quantity ?? 0);
        $currentPrice = $product->default_selling_price !== null ? (string) $product->default_selling_price : null;
        $compareAt = $product->compare_at_price !== null ? (string) $product->compare_at_price : null;
        $approvedReviews = $product->reviews()->where('status', 'approved');
        $ratingCount = (int) (clone $approvedReviews)->count();
        $averageRating = $ratingCount ? round((float) (clone $approvedReviews)->avg('rating'), 2) : null;
        $soldCount = (int) $product->orderItems()->where('fulfillment_status', 'delivered')->whereHas('order', fn ($q) => $q->whereIn('status', ['delivered','completed']))->sum('quantity');

        $variations = [];
        $priceFrom = null;
        $priceTo = null;

        if ($product->product_type === 'variable') {
            $variations = $product->variations->map(function ($v) use ($product) {
                return [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'attributes' => $v->attributes,
                    'current_selling_price' => $v->default_selling_price !== null ? (string) $v->default_selling_price : ($product->default_selling_price !== null ? (string) $product->default_selling_price : null),
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

        $discountAmount = $compareAt !== null && $currentPrice !== null && (float) $compareAt > (float) $currentPrice ? number_format((float) $compareAt - (float) $currentPrice, 2, '.', '') : null;
        $discount = $discountAmount === null ? null : ['amount' => $discountAmount, 'percentage' => (int) round(((float) $discountAmount / (float) $compareAt) * 100)];

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'seo_title' => $product->seo_title,
            'seo_description' => $product->seo_description,
            'description' => app(\App\Services\ProductHtmlSanitizer::class)->clean($product->description),
            'specifications' => $product->specifications ?? [],
            'specification_tables' => $product->specification_tables ?? [],
            'product_type' => $product->product_type,
            'status' => $product->status,
            'thumbnail' => $product->thumbnail,
            'gallery' => $product->gallery,
            'videos' => $product->videos ?? [],
            'weight_kg' => $product->weight_kg,
            'image_variants' => $product->image_variants,
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ] : null,
            'brand' => $product->brand?->name,
            'tags' => $product->tags->pluck('slug')->values()->all(),
            'store' => $store,
            'compare_at_price' => $compareAt,
            'discount' => $discount,
            'rating_summary' => ['average' => $averageRating, 'review_count' => $ratingCount, 'sold_count' => $soldCount],
            'option_groups' => $this->optionGroups($product),
            'size_chart' => $product->sizeChart ? $product->sizeChart->only(['id', 'name', 'url', 'unit', 'audience', 'category_slug', 'subcategory_slug', 'columns', 'rows', 'note']) : null,
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
            'brand:id,name,slug',
            'tags:id,name,slug',
            'seller:id,store_name,store_slug,store_logo,store_image,city,country,created_at,positive_rating_percentage,on_time_shipping_percentage,chat_response_percentage',
            'sizeChart',
            'variations' => function ($q) {
                $q->select(['id', 'product_id', 'sku', 'attributes', 'default_selling_price'])
                    ->selectSub($this->variationAvailableQtySubquery(), 'available_quantity');
            },
        ]);
    }

    private function applySearchFilters(Builder $query, Request $request): void
    {
        $request->validate(['brand' => ['sometimes', 'string', 'max:255'], 'tag' => ['sometimes', 'string', 'max:255']]);
        if ($request->filled('brand')) $query->whereHas('brand', fn (Builder $brand) => $brand->where('slug', $request->query('brand')));
        if ($request->filled('tag')) $query->whereHas('tags', fn (Builder $tag) => $tag->where('slug', $request->query('tag')));
        $search = (string) $request->query('search', '');
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhereHas('brand', fn (Builder $brand) => $brand->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('tags', fn (Builder $tag) => $tag->where('name', 'like', "%{$search}%"));
            });
        }
    }

    private function applyHomepageFilter(Builder $query, Request $request): void
    {
        $column = match ($request->query('homepage')) {
            'trending' => 'homepage_trending',
            'new_arrival' => 'homepage_new_arrival',
            'featured' => 'homepage_featured',
            default => null,
        };
        if ($column) $query->where($column, true);
    }

    private function applyPriceStockSubselects(Builder $query): void
    {
        $query->select([
            'id',
            'seller_id',
            'category_id',
            'brand_id',
            'name',
            'slug',
            'seo_title',
            'seo_description',
            'description',
            'specifications',
            'specification_tables',
            'product_type',
            'status',
            'thumbnail',
            'gallery',
            'compare_at_price', 'option_groups', 'size_chart_id', 'videos', 'weight_kg',
            'default_selling_price',
            'homepage_trending', 'homepage_new_arrival', 'homepage_featured', 'homepage_sort_order',
        ])->selectSub($this->productAvailableQtySubquery(), 'available_quantity');
    }

    private function productAvailableQtySubquery(): QueryBuilder
    {
        return DB::table('product_lots')
            ->selectRaw('coalesce(sum(quantity_remaining), 0)')
            ->whereColumn('product_lots.product_id', 'products.id');
    }

    private function variationAvailableQtySubquery(): QueryBuilder
    {
        return DB::table('product_lots')
            ->selectRaw('coalesce(sum(quantity_remaining), 0)')
            ->whereColumn('product_lots.variation_id', 'product_variations.id');
    }
}
