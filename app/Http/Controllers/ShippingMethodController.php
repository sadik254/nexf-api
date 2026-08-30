<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\ShippingMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ShippingMethodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            ShippingMethod::query()
                ->active()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->isSuperAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string'],
            'charge' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $code = Str::slug($data['code'], '_');
        if ($code === '') {
            return response()->json(['message' => 'Shipping method code is invalid.'], 422);
        }

        if (ShippingMethod::where('code', $code)->exists()) {
            return response()->json(['message' => 'Shipping method code already taken.'], 422);
        }

        $shippingMethod = ShippingMethod::create([
            'name' => $data['name'],
            'code' => $code,
            'description' => $data['description'] ?? null,
            'charge' => $data['charge'],
            'currency' => strtoupper($data['currency'] ?? 'BDT'),
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return response()->json([
            'message' => 'Shipping method created successfully.',
            'shipping_method' => $shippingMethod,
        ], 201);
    }

    public function update(Request $request, ShippingMethod $shippingMethod): JsonResponse
    {
        if (!$this->isSuperAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string'],
            'charge' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $code = $shippingMethod->code;
        if (array_key_exists('code', $data)) {
            $code = Str::slug($data['code'], '_');
            if ($code === '') {
                return response()->json(['message' => 'Shipping method code is invalid.'], 422);
            }

            $exists = ShippingMethod::where('code', $code)
                ->where('id', '!=', $shippingMethod->id)
                ->exists();

            if ($exists) {
                return response()->json(['message' => 'Shipping method code already taken.'], 422);
            }
        }

        $shippingMethod->fill([
            'name' => $data['name'] ?? $shippingMethod->name,
            'code' => $code,
            'description' => array_key_exists('description', $data) ? $data['description'] : $shippingMethod->description,
            'charge' => $data['charge'] ?? $shippingMethod->charge,
            'currency' => array_key_exists('currency', $data) ? strtoupper($data['currency']) : $shippingMethod->currency,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $shippingMethod->is_active,
            'sort_order' => $data['sort_order'] ?? $shippingMethod->sort_order,
        ])->save();

        return response()->json([
            'message' => 'Shipping method updated successfully.',
            'shipping_method' => $shippingMethod,
        ]);
    }

    public function destroy(Request $request, ShippingMethod $shippingMethod): JsonResponse
    {
        if (!$this->isSuperAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $shippingMethod->delete();

        return response()->json(['message' => 'Shipping method deleted successfully.']);
    }

    private function isSuperAdmin($actor): bool
    {
        return $actor instanceof Admin && $actor->role === 'super_admin';
    }
}
