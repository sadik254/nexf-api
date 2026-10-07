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

class WithdrawalController extends Controller
{
    public function customerIndex(Request $request): JsonResponse
    {
        $customer = $request->user(); abort_unless($customer instanceof Customer, 403);
        return response()->json(['balance' => $this->balance($customer->id), 'requests' => WithdrawalRequest::where('customer_id', $customer->id)->latest()->paginate(20)]);
    }
    public function customerStore(Request $request): JsonResponse
    {
        $customer = $request->user(); abort_unless($customer instanceof Customer, 403);
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:1'], 'method' => ['required', 'string', 'max:40'], 'account_details' => ['required', 'string', 'max:500']]);
        $withdrawal = DB::transaction(function () use ($customer, $data) {
            if ((float) $data['amount'] > $this->balance($customer->id, true)) throw ValidationException::withMessages(['amount' => ['Amount exceeds your available balance.']]);
            $request = WithdrawalRequest::create(['customer_id' => $customer->id] + $data);
            CustomerWalletTransaction::create(['customer_id' => $customer->id, 'type' => 'debit', 'amount' => $data['amount'], 'description' => "Withdrawal request #{$request->id}"]);
            return $request;
        });
        return response()->json($withdrawal, 201);
    }
    public function index(Request $request): JsonResponse { return response()->json(WithdrawalRequest::with('customer:id,name,email')->latest()->paginate(min(100, max(1, (int) $request->query('per_page', 25))))); }
    public function update(Request $request, WithdrawalRequest $withdrawal): JsonResponse
    {
        $admin = $request->user(); abort_unless($admin instanceof Admin, 403);
        $data = $request->validate(['status' => ['required', 'in:paid,rejected'], 'admin_note' => ['nullable', 'string', 'max:2000']]);
        if ($withdrawal->status !== 'requested') throw ValidationException::withMessages(['status' => ['Only pending requests may be processed.']]);
        DB::transaction(function () use ($withdrawal, $admin, $data) {
            if ($data['status'] === 'rejected') CustomerWalletTransaction::create(['customer_id' => $withdrawal->customer_id, 'type' => 'credit', 'amount' => $withdrawal->amount, 'description' => "Rejected withdrawal #{$withdrawal->id}"]);
            $withdrawal->update(['status' => $data['status'], 'admin_note' => $data['admin_note'] ?? null, 'processed_by_admin_id' => $admin->id, 'processed_at' => now()]);
        });
        return response()->json($withdrawal->refresh());
    }
    private function balance(int $customerId, bool $lock = false): float
    {
        $query = CustomerWalletTransaction::where('customer_id', $customerId); if ($lock) $query->lockForUpdate();
        return (float) $query->selectRaw("COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END), 0) total")->value('total');
    }
}
