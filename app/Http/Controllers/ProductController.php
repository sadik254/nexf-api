<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLot;
use App\Models\Seller;
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

        if (!$actor instanceof Admin) {
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
        if (!$actor instanceof Admin) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ((int) $product->seller_id !== (int) $seller->id) {
            return response()->json(['message' => 'Product does not belong to seller.'], 422);
        }

        return response()->json(
            $product->load(['category', 'seller', 'variations'])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:product_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'product_type' => ['required', 'in:simple,variable'],
            'status' => ['nullable', 'in:draft,active,inactive'],
            'thumbnail' => ['nullable', 'file', 'image', 'max:5120'],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => ['file', 'image', 'max:5120'],
            'default_buying_price' => ['nullable', 'numeric', 'min:0'],
            'default_selling_price' => ['nullable', 'numeric', 'min:0'],
        ]);

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

        $product = Product::create([
            'seller_id' => $actor instanceof Seller ? $actor->id : null,
            'created_by_admin_id' => $actor instanceof Admin ? $actor->id : null,
            'category_id' => $data['category_id'],
            'name' => $data['name'],
            'slug' => $slug,
            'description' => $data['description'] ?? null,
            'product_type' => $data['product_type'],
            'status' => $data['status'] ?? 'draft',
            'thumbnail' => $thumbnailUrl,
            'gallery' => $galleryUrls,
            'default_buying_price' => $data['default_buying_price'] ?? null,
            'default_selling_price' => $data['default_selling_price'] ?? null,
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
            'product_type' => ['sometimes', 'in:simple,variable'],
            'status' => ['sometimes', 'in:draft,active,inactive'],
            'thumbnail' => ['sometimes', 'file', 'image', 'max:5120'],
            'gallery' => ['sometimes', 'array'],
            'gallery.*' => ['file', 'image', 'max:5120'],
            'default_buying_price' => ['sometimes', 'numeric', 'min:0'],
            'default_selling_price' => ['sometimes', 'numeric', 'min:0'],
        ]);

        if (array_key_exists('name', $data)) {
            $product->slug = $this->uniqueSlug($data['name'], $product->id);
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
            'product_type' => $data['product_type'] ?? $product->product_type,
            'status' => $data['status'] ?? $product->status,
            'thumbnail' => $thumbnailUrl,
            'gallery' => $galleryUrls,
            'default_buying_price' => $data['default_buying_price'] ?? $product->default_buying_price,
            'default_selling_price' => $data['default_selling_price'] ?? $product->default_selling_price,
        ])->save();

        return response()->json([
            'message' => 'Product updated successfully.',
            'product' => $product,
        ]);
    }

    public function destroy(Product $product, Request $request): JsonResponse
    {
        $this->authorizeProductWrite($product, $request->user());
        $product->delete();

        return response()->json(['message' => 'Product deleted successfully.']);
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
