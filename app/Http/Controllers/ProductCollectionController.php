<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        return response()->json($query->paginate($perPage));
    }

    public function show(Request $request, ProductCollection $collection): JsonResponse
    {
        $this->authorizeCollection($this->actor($request), $collection);
        return response()->json($collection->load(['seller:id,store_name', 'products:id,seller_id,name,slug,thumbnail,status'])->loadCount('products'));
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
                'description' => $data['description'] ?? null,
            ]);
            $this->syncProducts($collection, $data['product_ids'] ?? []);
            return $collection;
        });
        return response()->json($collection->load(['seller:id,store_name', 'products:id,seller_id,name,slug,thumbnail,status'])->loadCount('products'), 201);
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
                'description' => $data['description'] ?? null,
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
        $collection->delete();
        return response()->json(['message' => 'Collection deleted.']);
    }

    private function validateInput(Request $request, Admin|Seller $actor, ?ProductCollection $existing = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:3000'],
            'seller_id' => ['nullable', 'integer', 'exists:sellers,id'],
            'product_ids' => ['required', 'array', 'max:200'],
            'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
        ]);
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
        abort_if($actor instanceof Admin && $actor->role !== 'super_admin', 403);
    }
}
