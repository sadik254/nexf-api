<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Seller;
use App\Models\SellerShippingRate;
use App\Models\ShippingMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerShippingRateController extends Controller
{
    public function sellerIndex(Request $request): JsonResponse { return $this->index($request->user()); }
    public function sellerUpdate(Request $request): JsonResponse { return $this->update($request->user(), $request); }

    public function adminIndex(Seller $seller, Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        return $this->index($seller);
    }

    public function adminUpdate(Seller $seller, Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        return $this->update($seller, $request);
    }

    private function index(Seller $seller): JsonResponse
    {
        $rates = SellerShippingRate::where('seller_id', $seller->id)->pluck('charge', 'shipping_method_id');
        return response()->json(ShippingMethod::query()->orderBy('sort_order')->get()->map(fn ($method) => [
            'shipping_method_id' => $method->id,
            'code' => $method->code,
            'name' => $method->name,
            'currency' => $method->currency,
            'is_active' => $method->is_active,
            'default_charge' => $method->charge,
            'charge' => $rates[$method->id] ?? $method->charge,
            'has_override' => $rates->has($method->id),
        ]));
    }

    private function update(Seller $seller, Request $request): JsonResponse
    {
        $data = $request->validate([
            'rates' => ['required', 'array', 'min:1'],
            'rates.*.shipping_method_id' => ['required', 'integer', 'distinct', 'exists:shipping_methods,id'],
            'rates.*.charge' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);
        DB::transaction(function () use ($seller, $data) {
            foreach ($data['rates'] as $rate) {
                SellerShippingRate::updateOrCreate(
                    ['seller_id' => $seller->id, 'shipping_method_id' => $rate['shipping_method_id']],
                    ['charge' => $rate['charge']],
                );
            }
        });
        return $this->index($seller);
    }

    private function authorizeAdmin(Request $request): void
    {
        $admin = $request->user();
        abort_unless($admin instanceof Admin && $admin->role === 'super_admin', 403);
    }
}
