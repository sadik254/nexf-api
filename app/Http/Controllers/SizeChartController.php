<?php

namespace App\Http\Controllers;

use App\Models\Seller;
use App\Models\SizeChart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SizeChartController extends Controller
{
    public function indexAdmin(): JsonResponse
    {
        return response()->json(SizeChart::query()->whereNull('seller_id')->latest()->get());
    }

    public function storeAdmin(Request $request): JsonResponse
    {
        $chart = SizeChart::create($this->validated($request));
        return response()->json(['message' => 'Size chart created successfully.', 'size_chart' => $chart], 201);
    }

    public function updateAdmin(SizeChart $sizeChart, Request $request): JsonResponse
    {
        $this->ensureGlobal($sizeChart);
        $sizeChart->update($this->validated($request, true));
        return response()->json(['message' => 'Size chart updated successfully.', 'size_chart' => $sizeChart->fresh()]);
    }

    public function destroyAdmin(SizeChart $sizeChart): JsonResponse
    {
        $this->ensureGlobal($sizeChart);
        $this->ensureUnused($sizeChart);
        $sizeChart->delete();
        return response()->json(['message' => 'Size chart deleted successfully.']);
    }

    public function indexSeller(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        return response()->json(SizeChart::query()->whereNull('seller_id')->orWhere('seller_id', $seller->id)->latest()->get());
    }

    public function storeSeller(Request $request): JsonResponse
    {
        $chart = SizeChart::create([...$this->validated($request), 'seller_id' => $this->seller($request)->id]);
        return response()->json(['message' => 'Size chart created successfully.', 'size_chart' => $chart], 201);
    }

    public function updateSeller(SizeChart $sizeChart, Request $request): JsonResponse
    {
        $this->ensureOwner($sizeChart, $this->seller($request));
        $sizeChart->update($this->validated($request, true));
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
        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'url' => [$partial ? 'sometimes' : 'required', 'url', 'max:2048'],
        ]);
    }

    private function seller(Request $request): Seller
    {
        $seller = $request->user();
        abort_unless($seller instanceof Seller, 403);
        return $seller;
    }

    private function ensureGlobal(SizeChart $sizeChart): void
    {
        abort_if($sizeChart->seller_id !== null, 403, 'This is a seller size chart.');
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
