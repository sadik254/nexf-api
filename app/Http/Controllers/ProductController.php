<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLot;
use App\Models\Seller;
use App\Models\SizeChart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
            ->with(['category', 'seller', 'variations'])
            ->latest();

        // Admin-store products are those not owned by a seller.
        $query->whereNull('seller_id');

        $this->applyListFilters($query, $request);

        return response()->json($query->paginate($perPage));
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
            ->with(['category', 'seller', 'variations'])
            ->where('seller_id', $seller->id)
            ->latest();

        $this->applyListFilters($query, $request);

        return response()->json($query->paginate($perPage));
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
            ->with(['category', 'seller', 'variations'])
            ->where('seller_id', $actor->id)
            ->latest();

        $this->applyListFilters($query, $request);

        return response()->json($query->paginate($perPage));
    }

    public function show(Product $product, Request $request): JsonResponse
    {
        $this->authorizeProductRead($product, $request->user());

        return response()->json(
            $product->load(['category', 'seller', 'variations'])
        );
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

        return response()->json(
            $product->load(['category', 'seller', 'variations'])
        );
    }

    public function updateSellerProductForSuperAdmin(Seller $seller, Product $product, Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$actor instanceof Admin || $actor->role !== 'super_admin') return response()->json(['message' => 'Forbidden.'], 403);
        if ((int) $product->seller_id !== (int) $seller->id) return response()->json(['message' => 'Product does not belong to seller.'], 422);
        $data = $request->validate(['category_id' => ['sometimes', 'integer', 'exists:product_categories,id'], 'name' => ['sometimes', 'string', 'max:255'], 'description' => ['sometimes', 'nullable', 'string'], 'specifications' => ['sometimes', 'nullable', 'array'], 'specifications.*.label' => ['required_with:specifications', 'string', 'max:120'], 'specifications.*.value' => ['required_with:specifications', 'string', 'max:500'], 'status' => ['sometimes', 'in:draft,active,inactive'], 'homepage_trending' => ['sometimes', 'boolean'], 'homepage_new_arrival' => ['sometimes', 'boolean'], 'homepage_featured' => ['sometimes', 'boolean'], 'homepage_sort_order' => ['sometimes', 'integer', 'min:0']]);
        $product->fill($data)->save();
        return response()->json(['message' => 'Seller product updated successfully.', 'product' => $product->fresh(['category', 'seller', 'variations'])]);
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'specifications' => ['nullable', 'array'],
            'specifications.*.label' => ['required_with:specifications', 'string', 'max:120'],
            'specifications.*.value' => ['required_with:specifications', 'string', 'max:500'],
            'product_type' => ['required', 'in:simple,variable'],
            'status' => ['nullable', 'in:draft,active,inactive'],
            'thumbnail' => ['nullable', 'file', 'image', 'max:5120'],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => ['file', 'image', 'max:5120'],
            'default_buying_price' => ['nullable', 'numeric', 'min:0'],
            'default_selling_price' => ['nullable', 'numeric', 'min:0'],
            'size_chart_id' => ['nullable', 'integer', 'exists:size_charts,id'],
            'homepage_trending' => ['nullable', 'boolean'],
            'homepage_new_arrival' => ['nullable', 'boolean'],
            'homepage_featured' => ['nullable', 'boolean'],
            'homepage_sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($actor instanceof Seller) {
            unset($data['homepage_trending'], $data['homepage_new_arrival'], $data['homepage_featured'], $data['homepage_sort_order']);
        }

        $slug = $this->uniqueSlug($data['name']);

        $uploadcare = $this->uploadcare();

        $thumbnailUrl = null;
        if ($request->hasFile('thumbnail')) {
            $file = $uploadcare->uploader()->fromPath(
                $request->file('thumbnail')->getPathname()
            );
            $thumbnailUrl = "https://ucarecdn.com/{$file->getUuid()}/-/preview/";
        }

        $galleryUrls = null;
        if ($request->hasFile('gallery')) {
            $galleryUrls = [];
            foreach ($request->file('gallery') as $image) {
                $file = $uploadcare->uploader()->fromPath($image->getPathname());
                $galleryUrls[] = "https://ucarecdn.com/{$file->getUuid()}/-/preview/";
            }
        }

        $this->authorizeSizeChart($data['size_chart_id'] ?? null, $actor);

        $product = Product::create([
            'seller_id' => $actor instanceof Seller ? $actor->id : null,
            'created_by_admin_id' => $actor instanceof Admin ? $actor->id : null,
            'category_id' => $data['category_id'],
            'name' => $data['name'],
            'slug' => $slug,
            'description' => $data['description'] ?? null,
            'specifications' => $data['specifications'] ?? null,
            'product_type' => $data['product_type'],
            'status' => $data['status'] ?? 'draft',
            'thumbnail' => $thumbnailUrl,
            'gallery' => $galleryUrls,
            'default_buying_price' => $data['default_buying_price'] ?? null,
            'default_selling_price' => $data['default_selling_price'] ?? null,
            'size_chart_id' => $data['size_chart_id'] ?? null,
            'homepage_trending' => (bool) ($data['homepage_trending'] ?? false),
            'homepage_new_arrival' => (bool) ($data['homepage_new_arrival'] ?? false),
            'homepage_featured' => (bool) ($data['homepage_featured'] ?? false),
            'homepage_sort_order' => $data['homepage_sort_order'] ?? 0,
        ]);

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
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'specifications' => ['sometimes', 'nullable', 'array'],
            'specifications.*.label' => ['required_with:specifications', 'string', 'max:120'],
            'specifications.*.value' => ['required_with:specifications', 'string', 'max:500'],
            'product_type' => ['sometimes', 'in:simple,variable'],
            'status' => ['sometimes', 'in:draft,active,inactive'],
            'thumbnail' => ['sometimes', 'file', 'image', 'max:5120'],
            'gallery' => ['sometimes', 'array'],
            'gallery.*' => ['file', 'image', 'max:5120'],
            'default_buying_price' => ['sometimes', 'numeric', 'min:0'],
            'default_selling_price' => ['sometimes', 'numeric', 'min:0'],
            'size_chart_id' => ['sometimes', 'nullable', 'integer', 'exists:size_charts,id'],
            'homepage_trending' => ['sometimes', 'boolean'],
            'homepage_new_arrival' => ['sometimes', 'boolean'],
            'homepage_featured' => ['sometimes', 'boolean'],
            'homepage_sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($request->user() instanceof Seller) {
            unset($data['homepage_trending'], $data['homepage_new_arrival'], $data['homepage_featured'], $data['homepage_sort_order']);
        }

        if (array_key_exists('name', $data)) {
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

        $galleryUrls = $product->gallery;
        if ($request->hasFile('gallery')) {
            $galleryUrls = [];
            foreach ($request->file('gallery') as $image) {
                $file = $uploadcare->uploader()->fromPath($image->getPathname());
                $galleryUrls[] = "https://ucarecdn.com/{$file->getUuid()}/-/preview/";
            }
        }

        $product->fill([
            'category_id' => $data['category_id'] ?? $product->category_id,
            'name' => $data['name'] ?? $product->name,
            'description' => $data['description'] ?? $product->description,
            'specifications' => array_key_exists('specifications', $data) ? $data['specifications'] : $product->specifications,
            'product_type' => $data['product_type'] ?? $product->product_type,
            'status' => $data['status'] ?? $product->status,
            'thumbnail' => $thumbnailUrl,
            'gallery' => $galleryUrls,
            'default_buying_price' => $data['default_buying_price'] ?? $product->default_buying_price,
            'default_selling_price' => $data['default_selling_price'] ?? $product->default_selling_price,
            'size_chart_id' => array_key_exists('size_chart_id', $data) ? $data['size_chart_id'] : $product->size_chart_id,
            'homepage_trending' => $data['homepage_trending'] ?? $product->homepage_trending,
            'homepage_new_arrival' => $data['homepage_new_arrival'] ?? $product->homepage_new_arrival,
            'homepage_featured' => $data['homepage_featured'] ?? $product->homepage_featured,
            'homepage_sort_order' => $data['homepage_sort_order'] ?? $product->homepage_sort_order,
        ])->save();

        return response()->json([
            'message' => 'Product updated successfully.',
            'product' => $product,
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

    private function applyListFilters($query, Request $request): void
    {
        $search = (string) $request->query('search', '');
        $categoryId = $request->query('category_id');
        $status = $request->query('status');
        $productType = $request->query('product_type');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($categoryId !== null && (string) $categoryId !== '') {
            $query->where('category_id', (int) $categoryId);
        }
        if ($status !== null && (string) $status !== '') {
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
}
