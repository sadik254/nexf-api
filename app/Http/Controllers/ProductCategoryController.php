<?php

namespace App\Http\Controllers;

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

        return response()->json(
            ProductCategory::query()->latest()->paginate($perPage)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $slug = $this->uniqueSlug($data['name']);

        $category = ProductCategory::create([
            'name' => $data['name'],
            'slug' => $slug,
            'description' => $data['description'] ?? null,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ]);

        return response()->json([
            'message' => 'Category created successfully.',
            'category' => $category,
        ], 201);
    }

    public function show(ProductCategory $category): JsonResponse
    {
        return response()->json($category);
    }

    public function update(Request $request, ProductCategory $category): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $data)) {
            $category->slug = $this->uniqueSlug($data['name'], $category->id);
        }

        $category->fill([
            'name' => $data['name'] ?? $category->name,
            'description' => $data['description'] ?? $category->description,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $category->is_active,
        ])->save();

        return response()->json([
            'message' => 'Category updated successfully.',
            'category' => $category,
        ]);
    }

    public function destroy(ProductCategory $category): JsonResponse
    {
        $category->delete();

        return response()->json(['message' => 'Category deleted successfully.']);
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
