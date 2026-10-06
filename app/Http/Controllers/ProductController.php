<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLot;
use App\Models\Seller;
use App\Models\SizeChart;
use App\Models\MediaAsset;
use App\Services\ProductHtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Uploadcare\Api;
use Uploadcare\Configuration;

class ProductController extends Controller
{
    public function indexAdminStore(Request $request): JsonResponse
    {
        $actor = $request->user();
        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(1, min($perPage, 100));

        if (!$actor instanceof Admin) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $query = Product::query()
            ->with(['category', 'brand', 'tags', 'seller', 'variations'])
            ->latest();

        // The product list can show the whole marketplace. Other callers keep
        // the existing admin-store default unless they explicitly opt in.
        if ($request->query('scope') === 'all' && $actor->role === 'super_admin') {
            if ($request->query('seller_id') === 'house') $query->whereNull('seller_id');
            elseif ($request->filled('seller_id')) $query->where('seller_id', (int) $request->query('seller_id'));
        } else {
            $query->whereNull('seller_id');
        }
        $this->withListMetrics($query);

        return response()->json($this->paginateProductList($query, $request, $perPage));
    }

    public function indexSellerProductsForAdmin(Seller $seller, Request $request): JsonResponse
    {
        $actor = $request->user();
        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(1, min($perPage, 100));

        if (!$actor instanceof Admin || $actor->role !== 'super_admin') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $query = Product::query()
            ->with(['category', 'brand', 'tags', 'seller', 'variations'])
            ->where('seller_id', $seller->id)
            ->latest();
        $this->withListMetrics($query);

        return response()->json($this->paginateProductList($query, $request, $perPage));
    }

    public function indexSellerSelf(Request $request): JsonResponse
    {
        $actor = $request->user();
        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(1, min($perPage, 100));

        if (!$actor instanceof Seller) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $query = Product::query()
            ->with(['category', 'brand', 'tags', 'seller', 'variations'])
            ->where('seller_id', $actor->id)
            ->latest();
        $this->withListMetrics($query);

        return response()->json($this->paginateProductList($query, $request, $perPage));
    }

    public function show(Product $product, Request $request): JsonResponse
    {
        $this->authorizeProductRead($product, $request->user());

        return response()->json($this->productForEditor($product));
    }

    public function showSellerProductForAdmin(Seller $seller, Product $product, Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$actor instanceof Admin || $actor->role !== 'super_admin') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ((int) $product->seller_id !== (int) $seller->id) {
            return response()->json(['message' => 'Product does not belong to seller.'], 422);
        }

        return response()->json($this->productForEditor($product));
    }

    private function productForEditor(Product $product): array
    {
        $product->load(['category', 'brand', 'tags', 'seller', 'variations']);
        $costLot = ProductLot::query()
            ->when($product->product_type === 'variable',
                fn ($query) => $query->whereIn('variation_id', $product->variations->pluck('id')),
                fn ($query) => $query->where('product_id', $product->id)->whereNull('variation_id'))
            ->where('quantity_remaining', '>', 0)->orderByRaw('received_at is null')->orderBy('received_at')->orderBy('id')->first();
        return array_merge($product->toArray(), [
            'current_buying_price' => $costLot?->buying_price,
        ]);
    }

    public function updateSellerProductForSuperAdmin(Seller $seller, Product $product, Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$actor instanceof Admin || $actor->role !== 'super_admin') return response()->json(['message' => 'Forbidden.'], 403);
        if ((int) $product->seller_id !== (int) $seller->id) return response()->json(['message' => 'Product does not belong to seller.'], 422);
        $data = $request->validate(['category_id' => ['sometimes', 'integer', 'exists:product_categories,id'], 'brand_id' => ['sometimes', 'nullable', 'integer', 'exists:brands,id'], 'tag_ids' => ['sometimes', 'array'], 'tag_ids.*' => ['integer', 'distinct', 'exists:tags,id'], 'clear_tags' => ['sometimes', 'boolean'], 'name' => ['sometimes', 'string', 'max:255'], 'slug' => ['sometimes', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')->ignore($product->id)], 'seo_title' => ['sometimes', 'nullable', 'string', 'max:70'], 'seo_description' => ['sometimes', 'nullable', 'string', 'max:170'], 'description' => ['sometimes', 'nullable', 'string'], 'specifications' => ['sometimes', 'nullable', 'array'], 'specification_tables' => ['sometimes', 'nullable', 'array'], 'specification_tables.*.title' => ['nullable', 'string', 'max:120'], 'specification_tables.*.rows' => ['required', 'array', 'max:100'], 'specification_tables.*.rows.*.label' => ['required', 'string', 'max:120'], 'specification_tables.*.rows.*.value' => ['required', 'string', 'max:500'], 'clear_specifications' => ['sometimes', 'boolean'], 'specifications.*.label' => ['required_with:specifications', 'string', 'max:120'], 'specifications.*.value' => ['required_with:specifications', 'string', 'max:500'], 'status' => ['sometimes', 'in:draft,active,inactive'], 'homepage_trending' => ['sometimes', 'boolean'], 'homepage_new_arrival' => ['sometimes', 'boolean'], 'homepage_featured' => ['sometimes', 'boolean'], 'homepage_sort_order' => ['sometimes', 'integer', 'min:0']]);
        if (array_key_exists('description', $data)) $data['description'] = app(ProductHtmlSanitizer::class)->clean($data['description']);
        if (array_key_exists('tag_ids', $data) || !empty($data['clear_tags'])) $product->tags()->sync($data['tag_ids'] ?? []);
        unset($data['tag_ids'], $data['clear_tags']);
        if (!empty($data['clear_specifications'])) { $data['specifications'] = []; $data['specification_tables'] = []; }
        unset($data['clear_specifications']);
        $product->fill($data)->save();
        return response()->json(['message' => 'Seller product updated successfully.', 'product' => $product->fresh(['category', 'brand', 'tags', 'seller', 'variations'])]);
    }

    public function destroySellerProductForSuperAdmin(Seller $seller, Product $product, Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$actor instanceof Admin || $actor->role !== 'super_admin') return response()->json(['message' => 'Forbidden.'], 403);
        if ((int) $product->seller_id !== (int) $seller->id) return response()->json(['message' => 'Product does not belong to seller.'], 422);
        if ($product->orderItems()->exists()) return response()->json(['message' => 'Products referenced by orders cannot be deleted. Mark inactive instead.'], 422);
        $product->delete();
        return response()->json(['message' => 'Seller product deleted successfully.']);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:product_categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', 'distinct', 'exists:tags,id'],
            'clear_tags' => ['sometimes', 'boolean'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')],
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:170'],
            'description' => ['nullable', 'string'],
            'specifications' => ['nullable', 'array'],
            'specification_tables' => ['nullable', 'array'],
            'specification_tables.*.title' => ['nullable', 'string', 'max:120'],
            'specification_tables.*.rows' => ['required', 'array', 'max:100'],
            'specification_tables.*.rows.*.label' => ['required', 'string', 'max:120'],
            'specification_tables.*.rows.*.value' => ['required', 'string', 'max:500'],
            'specifications.*.label' => ['required_with:specifications', 'string', 'max:120'],
            'specifications.*.value' => ['required_with:specifications', 'string', 'max:500'],
            'product_type' => ['required', 'in:simple,variable'],
            'status' => ['nullable', 'in:draft,active,inactive'],
            'thumbnail' => ['nullable', 'file', 'image', 'max:5120'],
            'thumbnail_media_id' => ['nullable', 'integer', 'exists:media_assets,id'],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => ['file', 'image', 'max:5120'],
            'gallery_media_ids' => ['nullable', 'array', 'max:30'],
            'gallery_media_ids.*' => ['integer', 'distinct', 'exists:media_assets,id'],
            'videos' => ['nullable', 'array', 'max:20'],
            'videos.*' => ['required', 'url', 'starts_with:https://'],
            'default_selling_price' => ['nullable', 'numeric', 'min:0'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'weight_kg' => ['nullable', 'numeric', 'min:0.1', 'max:999999'],
            'size_chart_id' => ['nullable', 'integer', 'exists:size_charts,id'],
            'homepage_trending' => ['nullable', 'boolean'],
            'homepage_new_arrival' => ['nullable', 'boolean'],
            'homepage_featured' => ['nullable', 'boolean'],
            'homepage_sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        if (array_key_exists('description', $data)) $data['description'] = app(ProductHtmlSanitizer::class)->clean($data['description']);

        if ($actor instanceof Seller) {
            unset($data['homepage_trending'], $data['homepage_new_arrival'], $data['homepage_featured'], $data['homepage_sort_order']);
        }

        $slug = $data['slug'] ?? $this->uniqueSlug($data['name']);

        $uploadcare = $this->uploadcare();

        $thumbnailUrl = null;
        if ($request->hasFile('thumbnail')) {
            $file = $uploadcare->uploader()->fromPath(
                $request->file('thumbnail')->getPathname()
            );
            $thumbnailUrl = "https://ucarecdn.com/{$file->getUuid()}/-/preview/";
        }
        if (!empty($data['thumbnail_media_id'])) {
            $thumbnailUrl = $this->imageMediaUrl((int) $data['thumbnail_media_id'], $actor instanceof Seller ? $actor->id : null);
        }

        $galleryUrls = null;
        if ($request->hasFile('gallery')) {
            $galleryUrls = [];
            foreach ($request->file('gallery') as $image) {
                $file = $uploadcare->uploader()->fromPath($image->getPathname());
                $galleryUrls[] = "https://ucarecdn.com/{$file->getUuid()}/-/preview/";
            }
        }
        if (!empty($data['gallery_media_ids'])) {
            $galleryUrls = array_merge($galleryUrls ?? [], array_map(
                fn ($id) => $this->imageMediaUrl((int) $id, $actor instanceof Seller ? $actor->id : null),
                $data['gallery_media_ids'],
            ));
        }

        $this->authorizeSizeChart($data['size_chart_id'] ?? null, $actor);

        $product = Product::create([
            'seller_id' => $actor instanceof Seller ? $actor->id : null,
            'created_by_admin_id' => $actor instanceof Admin ? $actor->id : null,
            'category_id' => $data['category_id'],
            'brand_id' => $data['brand_id'] ?? null,
            'name' => $data['name'],
            'slug' => $slug,
            'seo_title' => $data['seo_title'] ?? null,
            'seo_description' => $data['seo_description'] ?? null,
            'description' => $data['description'] ?? null,
            'specifications' => $data['specifications'] ?? null,
            'specification_tables' => $data['specification_tables'] ?? null,
            'product_type' => $data['product_type'],
            'status' => $data['status'] ?? 'draft',
            'thumbnail' => $thumbnailUrl,
            'gallery' => $galleryUrls,
            'videos' => $data['videos'] ?? null,
            'default_selling_price' => $data['default_selling_price'] ?? null,
            'compare_at_price' => $data['compare_at_price'] ?? null,
            'weight_kg' => $data['weight_kg'] ?? 0.5,
            'size_chart_id' => $data['size_chart_id'] ?? null,
            'homepage_trending' => (bool) ($data['homepage_trending'] ?? false),
            'homepage_new_arrival' => (bool) ($data['homepage_new_arrival'] ?? false),
            'homepage_featured' => (bool) ($data['homepage_featured'] ?? false),
            'homepage_sort_order' => $data['homepage_sort_order'] ?? 0,
        ]);

        if (!empty($data['tag_ids'])) $product->tags()->sync($data['tag_ids']);

        return response()->json([
            'message' => 'Product created successfully.',
            'product' => $product,
        ], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProductWrite($product, $request->user());

        $data = $request->validate([
            'category_id' => ['sometimes', 'integer', 'exists:product_categories,id'],
            'brand_id' => ['sometimes', 'nullable', 'integer', 'exists:brands,id'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'distinct', 'exists:tags,id'],
            'clear_tags' => ['sometimes', 'boolean'],
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')->ignore($product->id)],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:70'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:170'],
            'description' => ['sometimes', 'string'],
            'specifications' => ['sometimes', 'nullable', 'array'],
            'specification_tables' => ['sometimes', 'nullable', 'array'],
            'specification_tables.*.title' => ['nullable', 'string', 'max:120'],
            'specification_tables.*.rows' => ['required', 'array', 'max:100'],
            'specification_tables.*.rows.*.label' => ['required', 'string', 'max:120'],
            'specification_tables.*.rows.*.value' => ['required', 'string', 'max:500'],
            'clear_specifications' => ['sometimes', 'boolean'],
            'specifications.*.label' => ['required_with:specifications', 'string', 'max:120'],
            'specifications.*.value' => ['required_with:specifications', 'string', 'max:500'],
            'product_type' => ['sometimes', 'in:simple,variable'],
            'status' => ['sometimes', 'in:draft,active,inactive'],
            'thumbnail' => ['sometimes', 'file', 'image', 'max:5120'],
            'thumbnail_media_id' => ['sometimes', 'nullable', 'integer', 'exists:media_assets,id'],
            'gallery' => ['sometimes', 'array'],
            'gallery.*' => ['file', 'image', 'max:5120'],
            'gallery_media_ids' => ['sometimes', 'array', 'max:30'],
            'gallery_media_ids.*' => ['integer', 'distinct', 'exists:media_assets,id'],
            'videos' => ['sometimes', 'array', 'max:20'],
            'videos.*' => ['required', 'url', 'starts_with:https://'],
            'clear_videos' => ['sometimes', 'boolean'],
            'default_selling_price' => ['sometimes', 'numeric', 'min:0'],
            'compare_at_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'weight_kg' => ['sometimes', 'nullable', 'numeric', 'min:0.1', 'max:999999'],
            'size_chart_id' => ['sometimes', 'nullable', 'integer', 'exists:size_charts,id'],
            'homepage_trending' => ['sometimes', 'boolean'],
            'homepage_new_arrival' => ['sometimes', 'boolean'],
            'homepage_featured' => ['sometimes', 'boolean'],
            'homepage_sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (array_key_exists('description', $data)) $data['description'] = app(ProductHtmlSanitizer::class)->clean($data['description']);

        if ($request->user() instanceof Seller) {
            unset($data['homepage_trending'], $data['homepage_new_arrival'], $data['homepage_featured'], $data['homepage_sort_order']);
        }

        $tagIds = array_key_exists('tag_ids', $data) || !empty($data['clear_tags']) ? ($data['tag_ids'] ?? []) : null;
        unset($data['tag_ids'], $data['clear_tags']);
        if (!empty($data['clear_specifications'])) { $data['specifications'] = []; $data['specification_tables'] = []; }
        unset($data['clear_specifications']);
        if (!empty($data['clear_videos'])) $data['videos'] = [];
        unset($data['clear_videos']);

        if (array_key_exists('slug', $data)) {
            $product->slug = $data['slug'];
        } elseif (array_key_exists('name', $data)) {
            $product->slug = $this->uniqueSlug($data['name'], $product->id);
        }

        if (array_key_exists('size_chart_id', $data)) {
            $this->authorizeSizeChart($data['size_chart_id'], $request->user());
        }

        $uploadcare = $this->uploadcare();

        $thumbnailUrl = $product->thumbnail;
        if ($request->hasFile('thumbnail')) {
            $file = $uploadcare->uploader()->fromPath(
                $request->file('thumbnail')->getPathname()
            );
            $thumbnailUrl = "https://ucarecdn.com/{$file->getUuid()}/-/preview/";
        }
        if (!empty($data['thumbnail_media_id'])) {
            $thumbnailUrl = $this->imageMediaUrl((int) $data['thumbnail_media_id'], $product->seller_id);
        }

        $galleryUrls = $product->gallery;
        if ($request->hasFile('gallery')) {
            $galleryUrls = [];
            foreach ($request->file('gallery') as $image) {
                $file = $uploadcare->uploader()->fromPath($image->getPathname());
                $galleryUrls[] = "https://ucarecdn.com/{$file->getUuid()}/-/preview/";
            }
        }
        if (array_key_exists('gallery_media_ids', $data)) {
            $galleryUrls = array_merge($request->hasFile('gallery') ? ($galleryUrls ?? []) : [], array_map(
                fn ($id) => $this->imageMediaUrl((int) $id, $product->seller_id),
                $data['gallery_media_ids'],
            ));
        }

        $product->fill([
            'category_id' => $data['category_id'] ?? $product->category_id,
            'brand_id' => array_key_exists('brand_id', $data) ? $data['brand_id'] : $product->brand_id,
            'name' => $data['name'] ?? $product->name,
            'seo_title' => array_key_exists('seo_title', $data) ? $data['seo_title'] : $product->seo_title,
            'seo_description' => array_key_exists('seo_description', $data) ? $data['seo_description'] : $product->seo_description,
            'description' => $data['description'] ?? $product->description,
            'specifications' => array_key_exists('specifications', $data) ? $data['specifications'] : $product->specifications,
            'specification_tables' => array_key_exists('specification_tables', $data) ? $data['specification_tables'] : $product->specification_tables,
            'product_type' => $data['product_type'] ?? $product->product_type,
            'status' => $data['status'] ?? $product->status,
            'thumbnail' => $thumbnailUrl,
            'gallery' => $galleryUrls,
            'videos' => array_key_exists('videos', $data) ? $data['videos'] : $product->videos,
            'default_selling_price' => $data['default_selling_price'] ?? $product->default_selling_price,
            'compare_at_price' => array_key_exists('compare_at_price', $data) ? $data['compare_at_price'] : $product->compare_at_price,
            'weight_kg' => array_key_exists('weight_kg', $data) ? $data['weight_kg'] : $product->weight_kg,
            'size_chart_id' => array_key_exists('size_chart_id', $data) ? $data['size_chart_id'] : $product->size_chart_id,
            'homepage_trending' => $data['homepage_trending'] ?? $product->homepage_trending,
            'homepage_new_arrival' => $data['homepage_new_arrival'] ?? $product->homepage_new_arrival,
            'homepage_featured' => $data['homepage_featured'] ?? $product->homepage_featured,
            'homepage_sort_order' => $data['homepage_sort_order'] ?? $product->homepage_sort_order,
        ])->save();

        if ($tagIds !== null) $product->tags()->sync($tagIds);

        return response()->json([
            'message' => 'Product updated successfully.',
            'product' => $product->load(['brand', 'tags']),
        ]);
    }

    public function destroy(Product $product, Request $request): JsonResponse
    {
        $this->authorizeProductWrite($product, $request->user());

        if ($product->orderItems()->exists()) {
            return response()->json([
                'message' => 'Products referenced by orders cannot be deleted. Mark the product inactive instead.',
            ], 422);
        }

        $product->delete();

        return response()->json(['message' => 'Product deleted successfully.']);
    }

    /** Admin-store products may use global charts; sellers may use global or their own. */
    private function authorizeSizeChart(?int $sizeChartId, $actor): void
    {
        if ($sizeChartId === null) return;

        $chart = SizeChart::findOrFail($sizeChartId);
        if ($actor instanceof Admin && $chart->seller_id === null) return;
        if ($actor instanceof Seller && ($chart->seller_id === null || (int) $chart->seller_id === (int) $actor->id)) return;

        abort(403, 'You cannot use this size chart.');
    }

    private function authorizeProductRead(Product $product, $actor): void
    {
        if ($actor instanceof Seller && $product->seller_id !== $actor->id) {
            abort(403, 'Forbidden.');
        }
        // Admin can read all products via explicit endpoints.
    }

    private function authorizeProductWrite(Product $product, $actor): void
    {
        if ($actor instanceof Seller && $product->seller_id !== $actor->id) {
            abort(403, 'Forbidden.');
        }

        // Admin can only manage admin-store products (seller_id is null).
        if ($actor instanceof Admin && $product->seller_id !== null) {
            abort(403, 'Forbidden.');
        }
    }

    private function paginateProductList($query, Request $request, int $perPage): array
    {
        $request->validate([
            'status' => ['sometimes', 'in:draft,active,inactive'],
            'scope' => ['sometimes', 'in:all'],
            'seller_id' => ['sometimes', 'regex:/^(house|[1-9][0-9]*)$/'],
            'stock' => ['sometimes', 'in:ok,low,out'],
            'search' => ['sometimes', 'string', 'max:100'],
            'category_id' => ['sometimes', 'integer', 'min:1'],
            'product_type' => ['sometimes', 'in:simple,variable'],
        ]);
        $stockCounts = [];
        foreach (['ok', 'low', 'out'] as $level) {
            $stockCounts[$level] = (clone $query)->reorder()->whereRaw($this->stockFilterSql($level))->count('products.id');
        }
        $this->applyListFilters($query, $request, false);
        $counts = (clone $query)->reorder()->select('products.status')->selectRaw('count(*) as count')
            ->groupBy('products.status')->pluck('count', 'status')->map(fn ($count) => (int) $count)->all();
        if ($request->filled('status')) $query->where('status', $request->query('status'));
        if ($request->filled('stock')) $query->whereRaw($this->stockFilterSql((string) $request->query('stock')));
        $page = $query->paginate($perPage);
        $page->getCollection()->each(fn (Product $product) => $product->setAttribute('available_quantity', $product->product_type === 'simple'
            ? (int) ($product->direct_stock ?? 0)
            : (int) $product->variations->sum('stock')));
        return array_merge($page->toArray(), ['status_counts' => $counts, 'stock_counts' => $stockCounts]);
    }

    private function stockFilterSql(string $level): string
    {
        $stock = "CASE WHEN products.product_type = 'simple' THEN "
            . '(SELECT COALESCE(SUM(pl.quantity_remaining), 0) FROM product_lots pl WHERE pl.product_id = products.id AND pl.variation_id IS NULL) '
            . 'ELSE (SELECT COALESCE(SUM(pl.quantity_remaining), 0) FROM product_lots pl '
            . 'JOIN product_variations pv ON pv.id = pl.variation_id WHERE pv.product_id = products.id) END';
        return match ($level) {
            'ok' => "($stock) > 20",
            'low' => "($stock) BETWEEN 1 AND 20",
            default => "($stock) <= 0",
        };
    }

    private function withListMetrics($query): void
    {
        $query->with(['variations' => fn ($variations) => $variations->withSum('lots as stock', 'quantity_remaining')])
            ->withSum(['lots as direct_stock' => fn ($lots) => $lots->whereNull('variation_id')], 'quantity_remaining')
            ->withSum(['orderItems as sold_count' => fn ($items) => $items->whereHas('order', fn ($orders) => $orders->where('status', '!=', 'cancelled'))], 'quantity')
            ->withSum(['orderItems as profit_total' => fn ($items) => $items->whereHas('order', fn ($orders) => $orders->where('status', '!=', 'cancelled'))], 'line_profit')
            ->withAvg(['reviews as average_rating' => fn ($reviews) => $reviews->where('status', 'approved')], 'rating')
            ->withCount(['reviews as review_count' => fn ($reviews) => $reviews->where('status', 'approved')]);
    }

    private function applyListFilters($query, Request $request, bool $includeStatus = true): void
    {
        $search = (string) $request->query('search', '');
        $categoryId = $request->query('category_id');
        $status = $request->query('status');
        $productType = $request->query('product_type');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhereHas('brand', fn ($brands) => $brands->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('seller', fn ($sellers) => $sellers->where('store_name', 'like', "%{$search}%"));
            });
        }

        if ($categoryId !== null && (string) $categoryId !== '') {
            $query->where('category_id', (int) $categoryId);
        }
        if ($includeStatus && $status !== null && (string) $status !== '') {
            $query->where('status', (string) $status);
        }
        if ($productType !== null && (string) $productType !== '') {
            $query->where('product_type', (string) $productType);
        }
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base !== '' ? $base : Str::random(8);
        $counter = 1;

        $query = Product::query();
        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        while ($query->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function uploadcare(): Api
    {
        $configuration = Configuration::create(
            config('services.uploadcare.public_key'),
            config('services.uploadcare.secret_key')
        );
        return new Api($configuration);
    }

    private function imageMediaUrl(int $assetId, ?int $sellerId): string
    {
        $asset = MediaAsset::findOrFail($assetId);
        abort_unless($asset->mime_type && str_starts_with($asset->mime_type, 'image/'), 422, 'Selected media must be an image.');
        $allowed = $sellerId === null
            ? $asset->owner_type === 'admin'
            : $asset->owner_type === 'seller' && $asset->owner_id === $sellerId;
        abort_unless($allowed, 403, 'You cannot use this media asset.');
        return $asset->url;
    }
}
