<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductCollection;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductCollectionController extends Controller
{
    public function candidates(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $query = Product::query()->select(['id', 'seller_id', 'name', 'slug', 'thumbnail', 'status'])->orderBy('name');
        if ($actor instanceof Seller) $query->where('seller_id', $actor->id);
        elseif ($request->has('seller_id')) {
            $sellerId = $request->query('seller_id');
            $sellerId === 'platform' ? $query->whereNull('seller_id') : $query->where('seller_id', (int) $sellerId);
        }
        if ($request->filled('search')) $query->where('name', 'like', '%'.substr((string) $request->query('search'), 0, 100).'%');
        return response()->json($query->paginate(50));
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $perPage = max(1, min((int) $request->query('per_page', 25), 100));
        $query = ProductCollection::query()->with('seller:id,store_name')->withCount('products')->latest();
        if ($actor instanceof Seller) $query->where('seller_id', $actor->id);
        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.substr((string) $request->query('search'), 0, 100).'%');
        }
        $collections = $query->paginate($perPage);
        $collections->getCollection()->transform(fn (ProductCollection $collection) => $this->collectionPayload($collection));
        return response()->json($collections);
    }

    public function show(Request $request, ProductCollection $collection): JsonResponse
    {
        $this->authorizeCollection($this->actor($request), $collection);
        return response()->json($this->collectionPayload($collection->load(['seller:id,store_name', 'products:id,seller_id,name,slug,thumbnail,status'])->loadCount('products')));
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeWrite($actor);
        $data = $this->validateInput($request, $actor);
        $collection = DB::transaction(function () use ($actor, $data) {
            $collection = ProductCollection::create([
                'seller_id' => $actor instanceof Seller ? $actor->id : ($data['seller_id'] ?? null),
                'name' => trim($data['name']),
                'slug' => $this->uniqueSlug(trim($data['name'])),
                'description' => $data['description'] ?? null,
                'photo_category_ids' => $data['photo_category_ids'] ?? [],
            ]);
            $this->syncProducts($collection, $data['product_ids'] ?? []);
            return $collection;
        });
        return response()->json($this->collectionPayload($collection->load(['seller:id,store_name', 'products:id,seller_id,name,slug,thumbnail,status'])->loadCount('products')), 201);
    }

    public function update(Request $request, ProductCollection $collection): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeCollection($actor, $collection);
        $this->authorizeWrite($actor);
        $data = $this->validateInput($request, $actor, $collection);
        DB::transaction(function () use ($actor, $collection, $data) {
            $collection->update([
                'seller_id' => $actor instanceof Seller ? $actor->id : (array_key_exists('seller_id', $data) ? $data['seller_id'] : $collection->seller_id),
                'name' => trim($data['name']),
                'slug' => $this->uniqueSlug(trim($data['name']), $collection->id),
                'description' => $data['description'] ?? null,
                'photo_category_ids' => array_key_exists('photo_category_ids', $data) ? $data['photo_category_ids'] : $collection->photo_category_ids,
            ]);
            $this->syncProducts($collection, $data['product_ids'] ?? []);
        });
        return $this->show($request, $collection);
    }

    public function destroy(Request $request, ProductCollection $collection): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeCollection($actor, $collection);
        $this->authorizeWrite($actor);
        DB::transaction(function () use ($collection) {
            $coupons = \App\Models\Coupon::query()->whereJsonContains('eligible_collection_ids', $collection->id)->orWhereJsonContains('buy_collection_ids', $collection->id)->lockForUpdate()->get();
            foreach ($coupons as $coupon) {
                foreach (['eligible_collection_ids', 'buy_collection_ids'] as $key) {
                    $ids = $coupon->{$key};
                    if ($ids === null || !in_array($collection->id, $ids, true)) continue;
                    $coupon->{$key} = array_values(array_filter($ids, fn ($id) => $id !== $collection->id));
                    $prefix = $key === 'buy_collection_ids' ? 'buy' : 'eligible';
                    // A removed last target must never broaden a discount to the entire cart.
                    if (!$coupon->{$key} && !$coupon->{$prefix.'_product_ids'} && !$coupon->{$prefix.'_category_ids'}) $coupon->is_active = false;
                }
                $coupon->save();
            }
            $collection->delete();
        });
        return response()->json(['message' => 'Collection deleted.']);
    }

    /** Public collection metadata for rails and the collection shop page. */
    public function publicShow(string $collection): JsonResponse
    {
        $collection = ctype_digit($collection)
            ? ProductCollection::findOrFail((int) $collection)
            : ProductCollection::where('slug', $collection)->firstOrFail();
        abort_unless($collection->products()->where('status', 'active')->availableForSale()->exists(), 404);

        return response()->json([
            'id' => $collection->id,
            'name' => $collection->name,
            'slug' => $collection->slug,
            'photo_categories' => collect($this->photoCategories($collection->photo_category_ids ?? []))
                ->map(fn (array $category) => ['id' => $category['id'], 'slug' => $category['slug']])
                ->all(),
        ]);
    }

    private function validateInput(Request $request, Admin|Seller $actor, ?ProductCollection $existing = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:3000'],
            'seller_id' => ['nullable', 'integer', 'exists:sellers,id'],
            'product_ids' => ['required', 'array', 'max:200'],
            'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
            'photo_category_ids' => ['sometimes', 'array', 'max:30'],
            'photo_category_ids.*' => ['required', 'integer', 'distinct', 'exists:product_categories,id'],
        ]);
        if (array_key_exists('photo_category_ids', $data)) {
            $ids = array_map('intval', $data['photo_category_ids']);
            $topLevelCount = ProductCategory::query()->whereIn('id', $ids)->whereNull('parent_id')->count();
            if ($topLevelCount !== count($ids)) {
                throw ValidationException::withMessages(['photo_category_ids' => ['Choose only top-level categories.']]);
            }
            $data['photo_category_ids'] = $ids;
        }
        $sellerId = $actor instanceof Seller ? $actor->id : (array_key_exists('seller_id', $data) ? $data['seller_id'] : $existing?->seller_id);
        if ($sellerId !== null && Product::whereIn('id', $data['product_ids'])->where(fn ($query) => $query->whereNull('seller_id')->orWhere('seller_id', '!=', $sellerId))->exists()) {
            throw ValidationException::withMessages(['product_ids' => 'A store collection can contain only that store’s products.']);
        }
        return $data;
    }

    private function syncProducts(ProductCollection $collection, array $productIds): void
    {
        $collection->products()->sync(collect($productIds)->mapWithKeys(fn ($id, $order) => [$id => ['sort_order' => $order]])->all());
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'collection';
        $slug = $base;
        $suffix = 2;
        $query = ProductCollection::query();
        if ($ignoreId !== null) $query->whereKeyNot($ignoreId);
        while ((clone $query)->where('slug', $slug)->exists()) {
            $ending = '-'.$suffix++;
            $slug = Str::substr($base, 0, 180 - Str::length($ending)).$ending;
        }

        return $slug;
    }

    private function collectionPayload(ProductCollection $collection): array
    {
        $payload = $collection->toArray();
        $ids = array_values(array_unique(array_map('intval', $collection->photo_category_ids ?? [])));
        $payload['photo_category_ids'] = $ids;
        $payload['photo_categories'] = $this->photoCategories($ids);

        return $payload;
    }

    /** @return array<int, array{id:int,name:string,slug:string}> */
    private function photoCategories(array $ids): array
    {
        if (!$ids) return [];
        $categories = ProductCategory::query()->whereIn('id', $ids)->whereNull('parent_id')->get(['id', 'name', 'slug'])->keyBy('id');

        return collect($ids)->map(fn (int $id) => $categories->get($id))
            ->filter()
            ->map(fn (ProductCategory $category) => ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug])
            ->values()
            ->all();
    }

    private function actor(Request $request): Admin|Seller
    {
        $actor = $request->user();
        abort_unless($actor instanceof Admin || $actor instanceof Seller, 403);
        return $actor;
    }

    private function authorizeCollection(Admin|Seller $actor, ProductCollection $collection): void
    {
        abort_if($actor instanceof Seller && $collection->seller_id !== $actor->id, 404);
    }

    private function authorizeWrite(Admin|Seller $actor): void
    {
        abort_unless($actor instanceof Admin || $actor instanceof Seller, 403);
    }
}
