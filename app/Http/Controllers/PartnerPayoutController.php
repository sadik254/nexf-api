<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\OrderItem;
use App\Models\PartnerPayoutAccount;
use App\Models\PartnerWithdrawal;
use App\Models\Reseller;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PartnerPayoutController extends Controller
{
    private const MINIMUM = 500;
    private const HOLD_DAYS = 7;

    public function sellerPayouts(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        return response()->json($this->payload('seller', $seller));
    }

    public function resellerPayouts(Request $request): JsonResponse
    {
        $reseller = $this->reseller($request);
        return response()->json($this->payload('reseller', $reseller));
    }

    public function withdrawSeller(Request $request): JsonResponse
    {
        return $this->withdraw($request, 'seller', $this->seller($request));
    }

    public function withdrawReseller(Request $request): JsonResponse
    {
        return $this->withdraw($request, 'reseller', $this->reseller($request));
    }

    public function accounts(Request $request, string $type): JsonResponse
    {
        $owner = $this->owner($request, $type);
        return response()->json($this->accountsFor($type, $owner)->orderBy('id')->get()->map(fn (PartnerPayoutAccount $account) => $this->account($account)));
    }

    public function saveAccount(Request $request, string $type, ?int $account = null): JsonResponse
    {
        $owner = $this->owner($request, $type);
        foreach (['account', 'holder', 'bank', 'branch'] as $field) {
            if ($request->has($field)) $request->merge([$field => trim((string) $request->input($field))]);
        }
        $data = $request->validate([
            'provider' => ['required', Rule::in(['bkash', 'nagad', 'rocket', 'upay', 'bank'])],
            'account' => ['required', 'string', 'max:100', $request->input('provider') === 'bank' ? 'regex:/^\d{6,}$/' : 'regex:/^01\d{9}$/'],
            'holder' => ['nullable', 'string', 'max:150'],
            'bank' => ['required_if:provider,bank', 'nullable', 'string', 'max:150'],
            'branch' => ['nullable', 'string', 'max:150'],
        ]);
        $saved = DB::transaction(function () use ($type, $owner, $account, $data) {
            $rows = $this->accountsFor($type, $owner);
            $model = $account ? $rows->whereKey($account)->lockForUpdate()->firstOrFail() : new PartnerPayoutAccount($this->ownerKey($type, $owner));
            if (! $account) {
                $rows->update(['is_selected' => false]);
                $model->is_selected = true;
            }
            $model->fill($data)->save();
            return $model;
        });
        return response()->json($this->account($saved), $account ? 200 : 201);
    }

    public function selectAccount(Request $request, string $type, int $account): JsonResponse
    {
        $owner = $this->owner($request, $type);
        $selected = DB::transaction(function () use ($type, $owner, $account) {
            $rows = $this->accountsFor($type, $owner);
            $model = $rows->whereKey($account)->lockForUpdate()->firstOrFail();
            $rows->update(['is_selected' => false]);
            $model->update(['is_selected' => true]);
            return $model;
        });
        return response()->json($this->account($selected));
    }

    public function deleteAccount(Request $request, string $type, int $account): JsonResponse
    {
        $owner = $this->owner($request, $type);
        DB::transaction(function () use ($type, $owner, $account) {
            $rows = $this->accountsFor($type, $owner);
            $model = $rows->whereKey($account)->lockForUpdate()->firstOrFail();
            $selected = $model->is_selected;
            $model->delete();
            if ($selected) $rows->orderBy('id')->first()?->update(['is_selected' => true]);
        });
        return response()->json(['message' => 'Payout method deleted.']);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $data = $request->validate(['payee' => ['nullable', Rule::in(['seller', 'reseller'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $rows = PartnerWithdrawal::query()->with(['seller:id,seller_name,store_name,email,phone', 'reseller:id,name,email,phone', 'account'])
            ->when(($data['payee'] ?? null) === 'seller', fn ($query) => $query->whereNotNull('seller_id'))
            ->when(($data['payee'] ?? null) === 'reseller', fn ($query) => $query->whereNotNull('reseller_id'))
            ->latest()->paginate($data['per_page'] ?? 25);
        $rows->getCollection()->transform(fn (PartnerWithdrawal $row) => $this->adminRow($row));
        return response()->json($rows);
    }

    public function adminUpdate(Request $request, PartnerWithdrawal $partnerWithdrawal): JsonResponse
    {
        $admin = $request->user(); abort_unless($admin instanceof Admin, 403);
        $data = $request->validate(['status' => ['required', Rule::in(['paid', 'rejected'])], 'transaction_id' => ['nullable', 'string', 'max:255', 'required_if:status,paid'], 'admin_note' => ['nullable', 'string', 'max:2000']]);
        $row = DB::transaction(function () use ($partnerWithdrawal, $admin, $data) {
            $locked = PartnerWithdrawal::lockForUpdate()->findOrFail($partnerWithdrawal->id);
            if ($locked->status !== 'requested') throw ValidationException::withMessages(['status' => ['Only pending requests may be processed.']]);
            $locked->update(['status' => $data['status'], 'transaction_id' => $data['transaction_id'] ?? null, 'admin_note' => $data['admin_note'] ?? null, 'processed_by_admin_id' => $admin->id, 'processed_at' => now()]);
            return $locked->fresh(['seller:id,seller_name,store_name,email,phone', 'reseller:id,name,email,phone', 'account']);
        });
        return response()->json($this->adminRow($row));
    }

    private function withdraw(Request $request, string $type, Seller|Reseller $owner): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:' . self::MINIMUM], 'payout_account_id' => ['required', 'integer']]);
        $withdrawal = DB::transaction(function () use ($type, $owner, $data) {
            $account = $this->accountsFor($type, $owner)->whereKey($data['payout_account_id'])->lockForUpdate()->firstOrFail();
            $existing = $this->withdrawalsFor($type, $owner)->where('status', 'requested')->lockForUpdate()->exists();
            if ($existing) throw ValidationException::withMessages(['amount' => ['You already have a payout request in review.']]);
            $available = $this->balance($type, $owner)['available'];
            if ((float) $data['amount'] > $available) throw ValidationException::withMessages(['amount' => ['Amount exceeds your available balance.']]);
            $withdrawal = PartnerWithdrawal::create($this->ownerKey($type, $owner) + ['partner_payout_account_id' => $account->id, 'amount' => $data['amount']]);
            $withdrawal->update(['reference' => 'PAY-' . str_pad((string) $withdrawal->id, 5, '0', STR_PAD_LEFT)]);
            return $withdrawal;
        });
        return response()->json($withdrawal, 201);
    }

    private function payload(string $type, Seller|Reseller $owner): array
    {
        $balance = $this->balance($type, $owner);
        return ['balance' => $balance, 'commission_rate' => (float) $owner->commission_rate,
            'rules' => ['min_amount' => self::MINIMUM, 'hold_days' => $type === 'seller' ? self::HOLD_DAYS : 0],
            'earnings' => $this->earnings($type, $owner),
            'accounts' => $this->accountsFor($type, $owner)->orderBy('id')->get()->map(fn (PartnerPayoutAccount $account) => $this->account($account)),
            'withdrawals' => $this->withdrawalsFor($type, $owner)->latest()->get()];
    }

    private function balance(string $type, Seller|Reseller $owner): array
    {
        $earnings = collect($this->earnings($type, $owner));
        $settled = (float) $earnings->where('state', 'settled')->sum('amount');
        $settling = (float) $earnings->where('state', 'settling')->sum('amount');
        $withdrawals = $this->withdrawalsFor($type, $owner)->selectRaw("COALESCE(SUM(CASE WHEN status = 'requested' THEN amount ELSE 0 END), 0) pending, COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) paid")->first();
        $pending = (float) $withdrawals->pending; $paid = (float) $withdrawals->paid;
        return ['available' => round(max(0, $settled - $pending - $paid), 2), 'settling' => round($settling, 2), 'pending' => round($pending, 2), 'paid' => round($paid, 2), 'lifetime' => round($settled + $settling, 2)];
    }

    private function earnings(string $type, Seller|Reseller $owner): array
    {
        if ($type === 'seller') {
            return OrderItem::query()->where('seller_id', $owner->id)->where('fulfillment_status', 'delivered')->whereNotNull('delivered_at')->orderByDesc('delivered_at')->get()
                ->map(fn (OrderItem $item) => ['id' => 'item-' . $item->id, 'date' => $item->delivered_at->toDateString(), 'order_item_id' => $item->id, 'amount' => round((float) $item->line_subtotal * (1 - ((float) $owner->commission_rate / 100)), 2), 'state' => $item->delivered_at->lte(now()->subDays(self::HOLD_DAYS)) ? 'settled' : 'settling'])->all();
        }
        $months = [];
        for ($month = now()->copy()->startOfMonth()->subMonths(11); $month->lte(now()->copy()->startOfMonth()); $month->addMonth()) {
            $from = $month->copy()->startOfMonth(); $to = $month->copy()->endOfMonth();
            $totals = OrderItem::query()->whereHas('order', fn ($query) => $query->where('reseller_id', $owner->id)->whereBetween('created_at', [$from, $to])->whereIn('status', ['delivered', 'completed']))->selectRaw('COALESCE(SUM(quantity), 0) units, COALESCE(SUM(line_subtotal), 0) sales')->first();
            $amount = (int) $totals->units >= (int) $owner->monthly_target ? round((float) $totals->sales * (float) $owner->commission_rate / 100, 2) : 0;
            if ($amount > 0) $months[] = ['id' => 'month-' . $from->format('Y-m'), 'month' => $from->format('Y-m'), 'amount' => $amount, 'state' => $from->lt(now()->startOfMonth()) ? 'settled' : 'settling'];
        }
        return array_reverse($months);
    }

    private function seller(Request $request): Seller
    {
        abort_if($request->attributes->get('seller_staff'), 403);
        $seller = $request->user(); abort_unless($seller instanceof Seller, 403); return $seller;
    }
    private function reseller(Request $request): Reseller { $reseller = $request->user(); abort_unless($reseller instanceof Reseller, 403); return $reseller; }
    private function owner(Request $request, string $type): Seller|Reseller { return $type === 'seller' ? $this->seller($request) : $this->reseller($request); }
    private function ownerKey(string $type, Seller|Reseller $owner): array { return [$type . '_id' => $owner->id]; }
    private function accountsFor(string $type, Seller|Reseller $owner) { return PartnerPayoutAccount::query()->where($this->ownerKey($type, $owner)); }
    private function withdrawalsFor(string $type, Seller|Reseller $owner) { return PartnerWithdrawal::query()->where($this->ownerKey($type, $owner)); }
    private function account(PartnerPayoutAccount $account): array { return ['id' => (string) $account->id, 'provider' => $account->provider, 'account' => $account->account, 'holder' => $account->holder, 'bank' => $account->bank, 'branch' => $account->branch, 'isSelected' => $account->is_selected]; }
    private function adminRow(PartnerWithdrawal $row): array { $payee = $row->seller ?: $row->reseller; return $row->toArray() + ['payee' => ['name' => $payee?->seller_name ?? $payee?->name, 'email' => $payee?->email, 'phone' => $payee?->phone, 'store_name' => $payee instanceof Seller ? $payee->store_name : null], 'payee_type' => $row->seller_id ? 'seller' : 'reseller']; }
}
