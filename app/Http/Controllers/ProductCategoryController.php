<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(1, min($perPage, 100));

        $query = ProductCategory::query()->whereNull('parent_id')
            ->with(['children' => fn ($q) => $q->withCount('products')->orderBy('name')])
            ->withCount('products')->orderBy('name');
        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhereHas('children', fn ($children) => $children->where('name', 'like', "%{$search}%")));
        }
        return response()->json($query->paginate($perPage));
    }

    public function indexPublic(): JsonResponse
    {
        return response()->json(ProductCategory::query()->whereNull('parent_id')->where('is_active', true)
            ->with(['children' => fn ($q) => $q->where('is_active', true)->withCount('products')->orderBy('name')])
            ->withCount('products')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'parent_id' => ['nullable', 'integer', 'exists:product_categories,id'],
        ]);

        $this->validateParent($data['parent_id'] ?? null);

        $slug = $this->uniqueSlug($data['name']);

        $category = ProductCategory::create([
            'name' => $data['name'],
            'parent_id' => $data['parent_id'] ?? null,
            'slug' => $slug,
            'description' => $data['description'] ?? null,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ]);

        return response()->json([
            'message' => 'Category created successfully.',
            'category' => $category->load('children')->loadCount('products'),
        ], 201);
    }

    public function show(ProductCategory $category): JsonResponse
    {
        return response()->json($category->load(['parent', 'children'])->loadCount('products'));
    }

    public function update(Request $request, ProductCategory $category): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:product_categories,id'],
        ]);

        if (array_key_exists('parent_id', $data)) {
            if ((int) ($data['parent_id'] ?? 0) === $category->id) {
                return response()->json(['message' => 'A category cannot be its own parent.'], 422);
            }
            $this->validateParent($data['parent_id']);
            if ($category->children()->exists() && $data['parent_id'] !== null) {
                return response()->json(['message' => 'A category with subcategories cannot become a subcategory.'], 422);
            }
        }

        if (array_key_exists('name', $data)) {
            $category->slug = $this->uniqueSlug($data['name'], $category->id);
        }

        $category->fill([
            'name' => $data['name'] ?? $category->name,
            'parent_id' => array_key_exists('parent_id', $data) ? $data['parent_id'] : $category->parent_id,
            'description' => $data['description'] ?? $category->description,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $category->is_active,
        ])->save();

        return response()->json([
            'message' => 'Category updated successfully.',
            'category' => $category->fresh(['parent', 'children'])->loadCount('products'),
        ]);
    }

    public function destroy(ProductCategory $category): JsonResponse
    {
        if ($category->children()->exists()) {
            return response()->json(['message' => 'Delete or move this category’s subcategories first.'], 422);
        }
        if (Product::query()->where('category_id', $category->id)->exists()) {
            return response()->json([
                'message' => 'Categories with products cannot be deleted. Mark products inactive or move them first.',
            ], 422);
        }

        $category->delete();

        return response()->json(['message' => 'Category deleted successfully.']);
    }

    private function validateParent(?int $parentId): void
    {
        if (!$parentId) return;
        $parent = ProductCategory::findOrFail($parentId);
        abort_if($parent->parent_id !== null, 422, 'Only one subcategory level is supported.');
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base !== '' ? $base : Str::random(8);
        $counter = 1;

        $query = ProductCategory::query();
        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        while ($query->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
