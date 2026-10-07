<?php

namespace App\Http\Controllers;

use App\Models\Seller;
use App\Models\Admin;
use Illuminate\Validation\ValidationException;
use App\Models\SizeChart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SizeChartController extends Controller
{
    public function indexAdmin(Request $request): JsonResponse
    {
        return response()->json(SizeChart::query()->when($request->user()->role !== 'super_admin', fn ($q) => $q->whereNull('seller_id'))->with('seller:id,store_name')->withCount('products')->latest()->get());
    }

    public function storeAdmin(Request $request): JsonResponse
    {
        $chart = SizeChart::create($this->validated($request));
        return response()->json(['message' => 'Size chart created successfully.', 'size_chart' => $chart], 201);
    }

    public function updateAdmin(SizeChart $sizeChart, Request $request): JsonResponse
    {
        $this->ensureAdminAccess($sizeChart, $request);
        $data = $this->validated($request, true);
        if (array_key_exists('seller_id', $data) && $sizeChart->products()->where(function ($query) use ($data) {
            $data['seller_id'] === null ? $query->whereNotNull('seller_id') : $query->whereNull('seller_id')->orWhere('seller_id', '!=', $data['seller_id']);
        })->exists()) {
            throw ValidationException::withMessages(['seller_id' => ['This chart is assigned to products in another store. Remove those assignments before moving it.']]);
        }
        $sizeChart->update($data);
        return response()->json(['message' => 'Size chart updated successfully.', 'size_chart' => $sizeChart->fresh()]);
    }

    public function destroyAdmin(SizeChart $sizeChart, Request $request): JsonResponse
    {
        $this->ensureAdminAccess($sizeChart, $request);
        $this->ensureUnused($sizeChart);
        $sizeChart->delete();
        return response()->json(['message' => 'Size chart deleted successfully.']);
    }

    public function indexSeller(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        return response()->json(SizeChart::query()->whereNull('seller_id')->orWhere('seller_id', $seller->id)->with('seller:id,store_name')->withCount('products')->latest()->get());
    }

    public function storeSeller(Request $request): JsonResponse
    {
        $chart = SizeChart::create([...$this->validated($request), 'seller_id' => $this->seller($request)->id]);
        return response()->json(['message' => 'Size chart created successfully.', 'size_chart' => $chart], 201);
    }

    public function updateSeller(SizeChart $sizeChart, Request $request): JsonResponse
    {
        $this->ensureOwner($sizeChart, $this->seller($request));
        $data = $this->validated($request, true);
        if (array_key_exists('seller_id', $data) && $sizeChart->products()->where(function ($query) use ($data) {
            $data['seller_id'] === null ? $query->whereNotNull('seller_id') : $query->whereNull('seller_id')->orWhere('seller_id', '!=', $data['seller_id']);
        })->exists()) {
            throw ValidationException::withMessages(['seller_id' => ['This chart is assigned to products in another store. Remove those assignments before moving it.']]);
        }
        $sizeChart->update($data);
        return response()->json(['message' => 'Size chart updated successfully.', 'size_chart' => $sizeChart->fresh()]);
    }

    public function destroySeller(SizeChart $sizeChart, Request $request): JsonResponse
    {
        $this->ensureOwner($sizeChart, $this->seller($request));
        $this->ensureUnused($sizeChart);
        $sizeChart->delete();
        return response()->json(['message' => 'Size chart deleted successfully.']);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $data = $request->validate([
            'seller_id' => ['sometimes', 'nullable', 'integer', 'exists:sellers,id'],
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'url' => ['nullable', 'url', 'max:2048'],
            'unit' => ['sometimes', 'in:in,cm'],
            'audience' => ['sometimes', 'in:men,women,kids,unisex'],
            'category_slug' => ['nullable', 'string', 'exists:product_categories,slug'],
            'subcategory_slug' => ['nullable', 'string', 'exists:product_categories,slug'],
            'note' => ['nullable', 'string', 'max:5000'],
            'columns' => ['sometimes', 'array', 'max:30'],
            'columns.*.id' => ['required', 'string', 'max:100', 'distinct'],
            'columns.*.label' => ['required', 'string', 'max:120'],
            'rows' => ['sometimes', 'array', 'min:1', 'max:100'],
            'rows.*.id' => ['required', 'string', 'max:100', 'distinct'],
            'rows.*.label' => ['required', 'string', 'max:120'],
            'rows.*.values' => ['present', 'array'],
            'rows.*.values.*' => ['nullable', 'string', 'max:120'],
        ]);
        if ($request->user() instanceof Seller) unset($data['seller_id']);
        elseif (array_key_exists('seller_id', $data) && $data['seller_id'] !== null) {
            abort_unless($request->user() instanceof Admin && $request->user()->role === 'super_admin', 403);
        }
        if (!$partial && empty($data['url']) && empty($data['rows'])) {
            throw ValidationException::withMessages(['rows' => ['Add sizes and measurements to the chart.']]);
        }
        if (array_key_exists('rows', $data)) {
            foreach ($data['rows'] as &$row) {
                $row['values'] = array_map(fn ($value) => $value ?? '', $row['values']);
            }
            unset($row);
        }
        return $data;
    }

    private function seller(Request $request): Seller
    {
        $seller = $request->user();
        abort_unless($seller instanceof Seller, 403);
        return $seller;
    }

    private function ensureAdminAccess(SizeChart $sizeChart, Request $request): void
    {
        abort_unless($request->user() instanceof Admin && ($sizeChart->seller_id === null || $request->user()->role === 'super_admin'), 403, 'This is a seller size chart.');
    }

    private function ensureOwner(SizeChart $sizeChart, Seller $seller): void
    {
        abort_unless((int) $sizeChart->seller_id === (int) $seller->id, 403, 'Forbidden.');
    }

    private function ensureUnused(SizeChart $sizeChart): void
    {
        abort_if($sizeChart->products()->exists(), 422, 'Size charts assigned to products cannot be deleted.');
    }
}
