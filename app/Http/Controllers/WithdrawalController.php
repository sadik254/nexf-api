<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\WithdrawalRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\WalletBalanceService;

class WithdrawalController extends Controller
{
    public function customerIndex(Request $request): JsonResponse
    {
        $customer = $request->user(); abort_unless($customer instanceof Customer, 403);
        return response()->json(['balance' => $this->balance($customer->id), 'requests' => WithdrawalRequest::where('customer_id', $customer->id)->latest()->paginate(20)]);
    }
    public function wallet(Request $request): JsonResponse
    {
        $customer = $request->user(); abort_unless($customer instanceof Customer, 403);
        $transactions = CustomerWalletTransaction::where('customer_id', $customer->id)->with(['order:id,order_number', 'returnRequest.order:id,order_number'])->latest()->get();
        $transactions->each(function (CustomerWalletTransaction $transaction): void {
            $transaction->setAttribute('order_number', $transaction->order?->order_number ?? $transaction->returnRequest?->order?->order_number);
        });
        $withdrawals = WithdrawalRequest::where('customer_id', $customer->id)->latest()->get();
        $earned = $transactions->where('type', 'credit')->sum('amount');
        $spent = $transactions->where('type', 'debit')->sum('amount');
        return response()->json(['balance' => $earned - $spent, 'earned' => $earned, 'spent' => $spent,
            'transactions' => $transactions, 'withdrawals' => $withdrawals]);
    }
    public function customerStore(Request $request): JsonResponse
    {
        $customer = $request->user(); abort_unless($customer instanceof Customer, 403);
        $data = $request->validate(['payout_account_id' => ['sometimes', 'integer'], 'amount' => ['required', 'numeric', 'min:1'], 'method' => ['required_without:payout_account_id', 'string', 'max:40'], 'account_details' => ['required_without:payout_account_id', 'string', 'max:500']]);
        $withdrawal = DB::transaction(function () use ($customer, $data) {
            Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if (isset($data['payout_account_id'])) {
                $payout = \App\Models\CustomerPayoutAccount::where('customer_id', $customer->id)->whereKey($data['payout_account_id'])->firstOrFail();
                $data['method'] = $payout->provider;
                $data['account_details'] = json_encode($payout->only(['provider','account','holder','bank','branch']), JSON_UNESCAPED_UNICODE);
                unset($data['payout_account_id']);
            }
            if ((float) $data['amount'] > app(WalletBalanceService::class)->balance($customer->id, true)) throw ValidationException::withMessages(['amount' => ['Amount exceeds your available balance.']]);
            $request = WithdrawalRequest::create(['customer_id' => $customer->id] + $data);
            $request->update(['reference' => 'WDR-' . str_pad((string) $request->id, 5, '0', STR_PAD_LEFT)]);
            CustomerWalletTransaction::create(['customer_id' => $customer->id, 'type' => 'debit', 'kind' => 'withdrawal', 'amount' => $data['amount'], 'withdrawal_request_id' => $request->id, 'description' => "Withdrawal request {$request->reference}"]);
            return $request;
        });
        return response()->json($withdrawal, 201);
    }
    public function index(Request $request): JsonResponse { return response()->json(WithdrawalRequest::with('customer:id,name,email')->latest()->paginate(min(100, max(1, (int) $request->query('per_page', 25))))); }
    public function update(Request $request, WithdrawalRequest $withdrawal): JsonResponse
    {
        $admin = $request->user(); abort_unless($admin instanceof Admin, 403);
        $data = $request->validate(['status' => ['required', 'in:paid,rejected'], 'admin_note' => ['nullable', 'string', 'max:2000'], 'transaction_id' => ['nullable', 'string', 'max:255']]);
        DB::transaction(function () use ($withdrawal, $admin, $data) {
            Customer::whereKey($withdrawal->customer_id)->lockForUpdate()->firstOrFail();
            $locked = WithdrawalRequest::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'requested') throw ValidationException::withMessages(['status' => ['Only pending requests may be processed.']]);
            if ($data['status'] === 'rejected') CustomerWalletTransaction::create(['customer_id' => $locked->customer_id, 'type' => 'credit', 'kind' => 'withdrawal_reversal', 'amount' => $locked->amount, 'withdrawal_request_id' => $locked->id, 'description' => "Rejected withdrawal {$locked->reference}"]);
            $locked->update(['status' => $data['status'], 'admin_note' => $data['admin_note'] ?? null, 'transaction_id' => $data['transaction_id'] ?? null, 'processed_by_admin_id' => $admin->id, 'processed_at' => now()]);
        });
        return response()->json($withdrawal->refresh());
    }
    private function balance(int $customerId, bool $lock = false): float
    {
        return app(WalletBalanceService::class)->balance($customerId, $lock);
    }
}
