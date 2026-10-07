<?php

namespace App\Http\Controllers;

use App\Models\OrderItem;
use App\Models\Reseller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ResellerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $from = now()->startOfMonth();
        $to = now()->endOfMonth();
        $rows = Reseller::query()->when($search !== '', fn ($q) => $q->where(fn ($m) => $m->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->withCount('customers')->latest()->paginate(min(100, max(1, (int) $request->query('per_page', 25))));
        $rows->getCollection()->transform(fn (Reseller $reseller) => $this->withMonth($reseller, $from, $to));
        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $reseller = Reseller::create($this->validated($request));
        return response()->json($this->withMonth($reseller, now()->startOfMonth(), now()->endOfMonth()), 201);
    }

    public function update(Request $request, Reseller $reseller): JsonResponse
    {
        $reseller->update($this->validated($request, false));
        return response()->json($this->withMonth($reseller->refresh(), now()->startOfMonth(), now()->endOfMonth()));
    }

    public function destroy(Reseller $reseller): JsonResponse
    {
        $reseller->delete();
        return response()->json(['message' => 'Reseller removed.']);
    }

    public function commission(Request $request): JsonResponse
    {
        $month = $request->query('month');
        $from = $month ? now()->createFromFormat('Y-m', $month)->startOfMonth() : now()->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $resellers = Reseller::query()->where('is_active', true)->get()->map(fn (Reseller $reseller) => $this->withMonth($reseller, $from, $to));
        return response()->json(['month' => $from->format('Y-m'), 'resellers' => $resellers, 'commission_total' => round($resellers->sum('commission_earned'), 2)]);
    }

    private function validated(Request $request, bool $creating = true): array
    {
        $email = [($creating ? 'required' : 'sometimes'), 'email', 'max:255', Rule::unique('resellers', 'email')->ignore($request->route('reseller'))];
        return $request->validate([
            'name' => [($creating ? 'required' : 'sometimes'), 'string', 'max:255'], 'email' => $email,
            'phone' => ['nullable', 'string', 'max:32'], 'location' => ['nullable', 'string', 'max:255'],
            'commission_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'], 'monthly_target' => ['sometimes', 'integer', 'min:0', 'max:1000000'], 'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function withMonth(Reseller $reseller, $from, $to): Reseller
    {
        $totals = OrderItem::query()->whereHas('order', fn ($q) => $q->where('reseller_id', $reseller->id)->whereBetween('created_at', [$from, $to])->whereIn('status', ['delivered', 'completed']))
            ->selectRaw('COALESCE(SUM(quantity), 0) units, COALESCE(SUM(line_subtotal), 0) sales')->first();
        $units = (int) $totals->units; $sales = (float) $totals->sales;
        $reseller->setAttribute('units_this_month', $units);
        $reseller->setAttribute('sales_this_month', round($sales, 2));
        $reseller->setAttribute('target_met', $units >= $reseller->monthly_target);
        $reseller->setAttribute('commission_earned', $units >= $reseller->monthly_target ? round($sales * (float) $reseller->commission_rate / 100, 2) : 0);
        return $reseller;
    }
}
