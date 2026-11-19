<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Seller;
use App\Models\SiteSetting;
use App\Models\SizeChart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ProductCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(1, min($perPage, 100));

        $query = ProductCategory::query()->whereNull('parent_id')
            ->with(['children' => fn ($q) => $q->withCount('products')->orderBy('sort_order')->orderBy('name')])
            ->withCount('products')->orderBy('sort_order')->orderBy('name');
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
            ->with(['children' => fn ($q) => $q->where('is_active', true)->withCount(['products' => $activeProducts])->orderBy('sort_order')->orderBy('name')])
            ->withCount(['products' => $activeProducts])->orderBy('sort_order')->orderBy('name')->get();
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
            'name' => $data['name'], 'slug' => $this->uniqueSlugForCategory($data['name'], $parentId),
            'parent_id' => $parentId, 'is_active' => true, 'sort_order' => ProductCategory::nextSortOrder($parentId),
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
            'promo_href' => $this->promoHrefRules(),
            'promo_title' => ['nullable', 'string', 'max:120'], 'promo_caption' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'parent_id' => ['nullable', 'integer', 'exists:product_categories,id'],
        ]);

        $this->validateParent($data['parent_id'] ?? null);

        $parentId = $data['parent_id'] ?? null;
        $slug = $this->uniqueSlugForCategory($data['name'], $parentId);

        $category = ProductCategory::create([
            'name' => $data['name'],
            'parent_id' => $parentId,
            'sort_order' => ProductCategory::nextSortOrder($parentId),
            'slug' => $slug,
            'description' => $data['description'] ?? null,
            'image' => $data['image'] ?? null,
            'menu_heading' => $data['menu_heading'] ?? null,
            'promo_image' => $data['promo_image'] ?? null,
            'promo_href' => $data['promo_href'] ?? null, 'promo_title' => $data['promo_title'] ?? null, 'promo_caption' => $data['promo_caption'] ?? null,
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
            'promo_href' => ['sometimes', ...$this->promoHrefRules()],
            'promo_title' => ['sometimes', 'nullable', 'string', 'max:120'], 'promo_caption' => ['sometimes', 'nullable', 'string', 'max:255'],
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

        DB::transaction(function () use ($category, $data): void {
            $category = ProductCategory::whereKey($category->id)->lockForUpdate()->firstOrFail();
            $oldSlug = $category->slug;
            $value = fn (string $field) => array_key_exists($field, $data) ? $data[$field] : $category->{$field};
            $nextParentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $category->parent_id;
            $category->fill([
                'name' => $value('name'),
                'parent_id' => $nextParentId,
                'slug' => $this->uniqueSlugForCategory($value('name'), $nextParentId, $category->id),
                'description' => $value('description'),
                'image' => $value('image'),
                'menu_heading' => $value('menu_heading'),
                'promo_image' => $value('promo_image'),
                'promo_href' => $value('promo_href'), 'promo_title' => $value('promo_title'), 'promo_caption' => $value('promo_caption'),
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $category->is_active,
            ])->save();
            $this->rewriteSlugReferences($oldSlug, $category->slug);

            // A top-level rename changes every child's parent-name prefix.
            if ($category->parent_id === null) {
                foreach ($category->children()->lockForUpdate()->get() as $child) {
                    $oldChildSlug = $child->slug;
                    $child->update(['slug' => $this->uniqueSlug($child->name, $child->id, $category->name)]);
                    $this->rewriteSlugReferences($oldChildSlug, $child->slug);
                }
            }
        });

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

    /** The storefront accepts its own paths as well as safe external links. */
    private function promoHrefRules(): array
    {
        return ['nullable', 'string', 'max:2048', function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') return;
            $href = trim((string) $value);
            $isInternal = str_starts_with($href, '/') && !str_starts_with($href, '//');
            $scheme = parse_url($href, PHP_URL_SCHEME);
            if (! $isInternal && ! in_array($scheme, ['http', 'https'], true)) {
                $fail('The '.$attribute.' must be an internal path or an http(s) URL.');
            }
        }];
    }

    public function saveTaxonomy(Request $request, ?ProductCategory $category = null): JsonResponse
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'subcategories' => ['present', 'array', 'max:200'],
            'subcategories.*.id' => ['nullable', 'integer', 'distinct'], 'subcategories.*.name' => ['required', 'string', 'max:255']]);
        $names = collect($data['subcategories'])->map(fn ($sub) => mb_strtolower(trim($sub['name'])));
        if ($names->contains('') || $names->unique()->count() !== $names->count()) throw \Illuminate\Validation\ValidationException::withMessages(['subcategories' => 'Sub-category names must be non-empty and unique.']);
        $saved = DB::transaction(function () use ($data, $category) {
            if ($category) {
                $category = ProductCategory::whereKey($category->id)->lockForUpdate()->firstOrFail();
                abort_unless($category->parent_id === null, 422, 'Edit sub-categories through their parent.');
            } else $category = new ProductCategory(['is_active' => true, 'sort_order' => ProductCategory::nextSortOrder(null)]);
            $oldSlug = $category->slug;
            $category->fill(['name' => $data['name'], 'slug' => $this->uniqueSlugForCategory($data['name'], null, $category->id)])->save();
            if ($oldSlug !== null) $this->rewriteSlugReferences($oldSlug, $category->slug);
            $children = $category->children()->lockForUpdate()->get();
            $kept = [];
            foreach (array_values($data['subcategories']) as $position => $sub) {
                if (!empty($sub['id'])) {
                    $child = $children->firstWhere('id', $sub['id']);
                    if (!$child) throw \Illuminate\Validation\ValidationException::withMessages(['subcategories' => 'A sub-category does not belong to this category. Reload and try again.']);
                } else $child = new ProductCategory(['parent_id' => $category->id, 'is_active' => true]);
                $name = trim($sub['name']);
                $oldChildSlug = $child->slug;
                $child->fill(['name' => $name, 'slug' => $this->uniqueSlug($name, $child->id, $category->name), 'sort_order' => $position])->save();
                $this->rewriteSlugReferences($oldChildSlug, $child->slug);
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

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['present', 'array', 'max:200'], 'ids.*' => ['required', 'integer']]);
        $message = 'Send every top-level category once. Reload and try again.';

        \Illuminate\Support\Facades\DB::transaction(function () use ($data, $message) {
            $ids = array_map('intval', $data['ids']);
            $topLevel = ProductCategory::query()->whereNull('parent_id')->lockForUpdate()->pluck('id')->all();
            $valid = count($ids) === count(array_unique($ids))
                && count($ids) === count($topLevel)
                && !array_diff($ids, $topLevel)
                && !array_diff($topLevel, $ids);
            if (! $valid) {
                throw \Illuminate\Validation\ValidationException::withMessages(['ids' => [$message]]);
            }
            foreach ($ids as $position => $id) ProductCategory::whereKey($id)->update(['sort_order' => $position]);
        });

        return response()->json(['message' => 'Category order saved.']);
    }

    private function validateParent(?int $parentId): void
    {
        if (!$parentId) return;
        $parent = ProductCategory::findOrFail($parentId);
        abort_if($parent->parent_id !== null, 422, 'Only one subcategory level is supported.');
    }

    private function uniqueSlugForCategory(string $name, ?int $parentId, ?int $ignoreId = null): string
    {
        $parentName = $parentId === null ? null : ProductCategory::findOrFail($parentId)->name;

        return $this->uniqueSlug($name, $ignoreId, $parentName);
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null, ?string $parentName = null): string
    {
        $base = Str::slug($parentName === null ? $name : "{$parentName} {$name}");
        $slug = $base !== '' ? $base : Str::random(8);
        $counter = 1;

        $query = ProductCategory::query();
        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        while ((clone $query)->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /** Keep slug copies in charts and layout JSON in step with a renamed category. */
    private function rewriteSlugReferences(?string $oldSlug, string $newSlug): void
    {
        if ($oldSlug === null || $oldSlug === $newSlug) return;

        SizeChart::query()->where('category_slug', $oldSlug)->update(['category_slug' => $newSlug]);
        SizeChart::query()->where('subcategory_slug', $oldSlug)->update(['subcategory_slug' => $newSlug]);

        $layout = SiteSetting::query()->lockForUpdate()->find('home_layout');
        if ($layout) {
            $sections = $this->replaceLayoutCategorySlug($layout->payload ?? [], $oldSlug, $newSlug);
            if ($sections !== $layout->payload) $layout->update(['payload' => $sections]);
        }

        Seller::query()->whereNotNull('store_layout')->lockForUpdate()->eachById(function (Seller $seller) use ($oldSlug, $newSlug): void {
            $sections = $this->replaceLayoutCategorySlug($seller->store_layout ?? [], $oldSlug, $newSlug);
            if ($sections !== $seller->store_layout) $seller->forceFill(['store_layout' => $sections])->save();
        });
    }

    private function replaceLayoutCategorySlug(array $sections, string $oldSlug, string $newSlug): array
    {
        return array_map(function ($section) use ($oldSlug, $newSlug) {
            if (($section['source'] ?? null) === 'category' && ($section['sourceValue'] ?? null) === $oldSlug) {
                $section['sourceValue'] = $newSlug;
            }

            return $section;
        }, $sections);
    }
}
