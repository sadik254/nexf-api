<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Brand;
use App\Models\Tag;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CatalogTermController extends Controller
{
    public function brands(Request $request): JsonResponse { return $this->index(Brand::class, $request); }
    public function tags(Request $request): JsonResponse { return $this->index(Tag::class, $request); }

    private function index(string $model, Request $request): JsonResponse
    {
        $request->validate(['search' => ['sometimes', 'string', 'max:100']]);
        $query = $model::query()->withCount('products')->orderBy('name');
        if ($request->filled('search')) $query->where('name', 'like', '%'.$request->query('search').'%');
        return response()->json($query->get());
    }

    public function storeBrand(Request $request): JsonResponse { return $this->store(Brand::class, $request); }
    public function storeTag(Request $request): JsonResponse { return $this->store(Tag::class, $request); }

    /** Product editors may add keywords without receiving tag edit/delete access. */
    public function storeProductTag(Request $request): JsonResponse
    {
        abort_unless($request->user() instanceof Admin || $request->user() instanceof Seller, 403);
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $name = $request->validate(['name' => ['required', 'string', 'max:255']])['name'];
        $existing = Tag::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if ($existing) return response()->json($existing->loadCount('products'));
        $tag = Tag::create(['name' => $name, 'slug' => $this->uniqueSlug(Tag::class, $name)]);
        return response()->json($tag->loadCount('products'), 201);
    }

    private function store(string $model, Request $request): JsonResponse
    {
        $this->authorizeManager($request);
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $name = $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique((new $model)->getTable())]])['name'];
        $slug = $this->uniqueSlug($model, $name);
        $term = $model::create(['name' => trim($name), 'slug' => $slug]);
        return response()->json($term->loadCount('products'), 201);
    }

    public function updateBrand(Request $request, Brand $brand): JsonResponse { return $this->update($request, $brand); }
    public function updateTag(Request $request, Tag $tag): JsonResponse { return $this->update($request, $tag); }

    private function update(Request $request, Brand|Tag $term): JsonResponse
    {
        $this->authorizeManager($request);
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $name = $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique($term->getTable())->ignore($term->id)]])['name'];
        $term->update(['name' => trim($name), 'slug' => $this->uniqueSlug($term::class, $name, $term->id)]);
        return response()->json($term->loadCount('products'));
    }

    public function deleteBrand(Request $request, Brand $brand): JsonResponse { return $this->delete($request, $brand); }
    public function deleteTag(Request $request, Tag $tag): JsonResponse { return $this->delete($request, $tag); }

    private function delete(Request $request, Brand|Tag $term): JsonResponse
    {
        $this->authorizeManager($request);
        if ($term->products()->exists()) return response()->json(['message' => 'This item is used by products and cannot be deleted.'], 422);
        $term->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    private function authorizeManager(Request $request): void
    {
        $admin = $request->user();
        abort_unless($admin instanceof Admin && $admin->role === 'super_admin', 403);
    }

    private function uniqueSlug(string $model, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: Str::random(8);
        $slug = $base;
        for ($number = 2; $model::where('slug', $slug)->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))->exists(); $number++) {
            $slug = $base.'-'.$number;
        }
        return $slug;
    }
}
