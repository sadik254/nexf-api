<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentMethodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // dd($request->user());
        return response()->json(
            PaymentMethod::query()
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
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $code = Str::slug($data['code'], '_');
        if ($code === '') {
            return response()->json(['message' => 'Payment method code is invalid.'], 422);
        }

        if (PaymentMethod::where('code', $code)->exists()) {
            return response()->json(['message' => 'Payment method code already taken.'], 422);
        }

        $paymentMethod = PaymentMethod::create([
            'name' => $data['name'],
            'code' => $code,
            'description' => $data['description'] ?? null,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return response()->json([
            'message' => 'Payment method created successfully.',
            'payment_method' => $paymentMethod,
        ], 201);
    }

    public function update(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        if (!$this->isSuperAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $code = $paymentMethod->code;
        if (array_key_exists('code', $data)) {
            $code = Str::slug($data['code'], '_');
            if ($code === '') {
                return response()->json(['message' => 'Payment method code is invalid.'], 422);
            }

            $exists = PaymentMethod::where('code', $code)
                ->where('id', '!=', $paymentMethod->id)
                ->exists();

            if ($exists) {
                return response()->json(['message' => 'Payment method code already taken.'], 422);
            }
        }

        $paymentMethod->fill([
            'name' => $data['name'] ?? $paymentMethod->name,
            'code' => $code,
            'description' => array_key_exists('description', $data) ? $data['description'] : $paymentMethod->description,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $paymentMethod->is_active,
            'sort_order' => $data['sort_order'] ?? $paymentMethod->sort_order,
        ])->save();

        return response()->json([
            'message' => 'Payment method updated successfully.',
            'payment_method' => $paymentMethod,
        ]);
    }

    public function destroy(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        if (!$this->isSuperAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $paymentMethod->delete();

        return response()->json(['message' => 'Payment method deleted successfully.']);
    }

    private function isSuperAdmin($actor): bool
    {
        return $actor instanceof Admin && $actor->role === 'super_admin';
    }
}
