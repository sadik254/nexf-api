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
        $activeProducts = fn ($q) => $q->where('status', 'active');
        $categories = ProductCategory::query()->whereNull('parent_id')->where('is_active', true)
            ->with(['children' => fn ($q) => $q->where('is_active', true)->withCount(['products' => $activeProducts])->orderBy('name')])
            ->withCount(['products' => $activeProducts])->orderBy('name')->get();
        $categories->each(fn ($category) => $category->setAttribute(
            'total_products_count',
            $category->products_count + $category->children->sum('products_count')
        ));
        return response()->json($categories);
    }

    /** Inline creation may reuse a category, but never change an existing one. */
    public function storeProductCategory(Request $request): JsonResponse
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:product_categories,id'],
        ]);
        $parentId = $data['parent_id'] ?? null;
        $this->validateParent($parentId);
        $existing = ProductCategory::where('parent_id', $parentId)->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])->first();
        if ($existing) return response()->json($existing->load('children')->loadCount('products'));
        $category = ProductCategory::create([
            'name' => $data['name'], 'slug' => $this->uniqueSlug($data['name']),
            'parent_id' => $parentId, 'is_active' => true,
        ]);
        return response()->json($category->load('children')->loadCount('products'), 201);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'url', 'starts_with:https://'],
            'menu_heading' => ['nullable', 'string', 'max:120'],
            'promo_image' => ['nullable', 'url', 'starts_with:https://'],
            'promo_href' => ['nullable', 'url', 'starts_with:https://'],
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
            'image' => $data['image'] ?? null,
            'menu_heading' => $data['menu_heading'] ?? null,
            'promo_image' => $data['promo_image'] ?? null,
            'promo_href' => $data['promo_href'] ?? null,
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
            'image' => ['sometimes', 'nullable', 'url', 'starts_with:https://'],
            'menu_heading' => ['sometimes', 'nullable', 'string', 'max:120'],
            'promo_image' => ['sometimes', 'nullable', 'url', 'starts_with:https://'],
            'promo_href' => ['sometimes', 'nullable', 'url', 'starts_with:https://'],
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
            'image' => $data['image'] ?? $category->image,
            'menu_heading' => $data['menu_heading'] ?? $category->menu_heading,
            'promo_image' => $data['promo_image'] ?? $category->promo_image,
            'promo_href' => $data['promo_href'] ?? $category->promo_href,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $category->is_active,
        ])->save();

        return response()->json([
            'message' => 'Category updated successfully.',
            'category' => $category->fresh(['parent', 'children'])->loadCount('products'),
        ]);
    }

    public function destroy(ProductCategory $category): JsonResponse
    {
        $categoryIds = $category->children()->pluck('id')->prepend($category->id);
        if (Product::query()->whereIn('category_id', $categoryIds)->exists()) {
            return response()->json([
                'message' => 'Categories with products cannot be deleted. Mark products inactive or move them first.',
            ], 422);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($category) {
            $category->children()->delete();
            $category->delete();
        });

        return response()->json(['message' => 'Category deleted successfully.']);
    }

    public function saveTaxonomy(Request $request, ?ProductCategory $category = null): JsonResponse
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'subcategories' => ['present', 'array', 'max:200'],
            'subcategories.*.id' => ['nullable', 'integer', 'distinct'], 'subcategories.*.name' => ['required', 'string', 'max:255']]);
        $names = collect($data['subcategories'])->map(fn ($sub) => mb_strtolower(trim($sub['name'])));
        if ($names->contains('') || $names->unique()->count() !== $names->count()) throw \Illuminate\Validation\ValidationException::withMessages(['subcategories' => 'Sub-category names must be non-empty and unique.']);
        $saved = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $category) {
            if ($category) {
                $category = ProductCategory::whereKey($category->id)->lockForUpdate()->firstOrFail();
                abort_unless($category->parent_id === null, 422, 'Edit sub-categories through their parent.');
            } else $category = new ProductCategory(['is_active' => true]);
            $category->fill(['name' => $data['name'], 'slug' => $this->uniqueSlug($data['name'], $category->id)])->save();
            $children = $category->children()->lockForUpdate()->get();
            $kept = [];
            foreach ($data['subcategories'] as $sub) {
                if (!empty($sub['id'])) {
                    $child = $children->firstWhere('id', $sub['id']);
                    if (!$child) throw \Illuminate\Validation\ValidationException::withMessages(['subcategories' => 'A sub-category does not belong to this category. Reload and try again.']);
                } else $child = new ProductCategory(['parent_id' => $category->id, 'is_active' => true]);
                $name = trim($sub['name']);
                $child->fill(['name' => $name, 'slug' => $this->uniqueSlug($name, $child->id)])->save();
                $kept[] = $child->id;
            }
            foreach ($children->whereNotIn('id', $kept) as $removed) {
                // Removing a sub-label retains every product under the parent taxonomy.
                Product::where('category_id', $removed->id)->update(['category_id' => $category->id]);
                $removed->delete();
            }
            return $category->fresh(['children'])->loadCount('products');
        });
        return response()->json(['category' => $saved]);
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
