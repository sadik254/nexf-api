<?php

namespace App\Http\Controllers;

use App\Models\OrderItem;
use App\Models\Reseller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use App\Services\PasswordResetCodeService;
use Illuminate\Support\Carbon;

class ResellerController extends Controller
{
    public function __construct(private PasswordResetCodeService $passwordResets) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $reseller = Reseller::where('email', $data['email'])->first();
        if (!$reseller || !$reseller->password || !Hash::check($data['password'], $reseller->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }
        if (!$reseller->is_active) return response()->json(['message' => 'Account is inactive.'], 403);
        return response()->json(['token' => $reseller->createToken('reseller-api', ['reseller:basic'])->plainTextToken, 'reseller' => $reseller]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $reseller = Reseller::where('email', $data['email'])->where('is_active', true)->first();
        if ($reseller) $this->passwordResets->send($reseller, 'reseller');
        return response()->json(['message' => 'If that email exists, a code has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'code' => ['required', 'digits:6'], 'password' => ['required', 'string', 'min:8', 'confirmed']]);
        $reseller = Reseller::where('email', $data['email'])->where('is_active', true)->first();
        if (!$reseller || !$this->passwordResets->consume($reseller, 'reseller', $data['code'])) return response()->json(['message' => 'Invalid or expired code.'], 422);
        $reseller->forceFill(['password' => $data['password']])->save();
        $reseller->tokens()->delete();
        return response()->json(['message' => 'Password set successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        $reseller = $request->user();
        if (!$reseller || !$reseller->is_active) return response()->json(['message' => 'Unauthorized.'], 401);
        $data = $request->validate(['month' => ['sometimes', 'date_format:Y-m']]);
        $month = isset($data['month']) ? now()->createFromFormat('Y-m', $data['month']) : now();
        return response()->json($this->withMonth($reseller, $month->copy()->startOfMonth(), $month->copy()->endOfMonth()));
    }

    public function commissionPeriod(Request $request): JsonResponse
    {
        $reseller = $request->user();
        if (!$reseller || !$reseller->is_active) return response()->json(['message' => 'Unauthorized.'], 401);
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m'],
            'to' => ['required', 'date_format:Y-m', 'after_or_equal:from'],
        ]);
        $from = Carbon::createFromFormat('Y-m', $data['from'])->startOfMonth();
        $to = Carbon::createFromFormat('Y-m', $data['to'])->startOfMonth();
        if ($from->diffInMonths($to) > 23) return response()->json(['message' => 'Select a range of 24 months or less.'], 422);
        $months = [];
        for ($month = $from->copy(); $month->lte($to); $month->addMonth()) {
            $row = $this->withMonth(clone $reseller, $month->copy()->startOfMonth(), $month->copy()->endOfMonth());
            $months[] = [
                'month' => $month->format('Y-m'),
                'units' => (int) $row->units_this_month,
                'sales' => (float) $row->sales_this_month,
                'target_met' => (bool) $row->target_met,
                'commission_earned' => (float) $row->commission_earned,
                'potential_commission' => round((float) $row->sales_this_month * (float) $row->commission_rate / 100, 2),
            ];
        }
        return response()->json([
            'from' => $data['from'], 'to' => $data['to'],
            'reseller' => $reseller,
            'months' => $months,
            'units' => array_sum(array_column($months, 'units')),
            'sales' => round(array_sum(array_column($months, 'sales')), 2),
            'commission_earned' => round(array_sum(array_column($months, 'commission_earned')), 2),
            'months_on_target' => count(array_filter($months, fn (array $month) => $month['target_met'])),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $reseller = $request->user();
        $data = $request->validate(['current_password' => ['required', 'string'], 'new_password' => ['required', 'string', 'min:8', 'confirmed']]);
        if (!Hash::check($data['current_password'], $reseller->password ?? '')) return response()->json(['message' => 'Current password is incorrect.'], 422);
        if ($data['current_password'] === $data['new_password']) return response()->json(['message' => 'New password must be different from current password.'], 422);
        $reseller->forceFill(['password' => $data['new_password']])->save();
        $reseller->tokens()->delete();
        return response()->json(['message' => 'Password updated successfully.']);
    }

    public function updateMe(Request $request): JsonResponse
    {
        $reseller = $request->user();
        abort_unless($reseller instanceof Reseller && $reseller->is_active, 403);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('resellers', 'email')->ignore($reseller->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', Rule::unique('resellers', 'phone')->ignore($reseller->id)],
            'address_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_area' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_district' => ['sometimes', 'nullable', 'string', 'max:255'],
            'image' => ['sometimes', 'file', 'image', 'max:5120'],
            'clear_image' => ['sometimes', 'boolean'],
        ]);
        if (!empty($data['clear_image'])) $data['image'] = null;
        if ($request->hasFile('image')) $data['image'] = app(\App\Services\MediaUploadService::class)->upload($request->file('image'));
        unset($data['clear_image']);
        $reseller->fill($data)->save();
        return response()->json($reseller->fresh());
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['message' => 'Logged out successfully.']);
    }

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
        $this->passwordResets->send($reseller, 'reseller');
        $reseller->setAttribute('invite_sent', true);
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
