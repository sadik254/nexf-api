<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Reseller;
use App\Models\Seller;
use App\Models\SellerStaff;
use App\Services\PasswordResetCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasswordResetCheckController extends Controller
{
    private const ACCOUNTS = ['customer' => Customer::class, 'seller' => Seller::class, 'reseller' => Reseller::class, 'admin' => Admin::class];
    public function __construct(private PasswordResetCodeService $passwordResets) {}

    public function __invoke(Request $request, string $accountType): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'code' => ['required', 'digits:6']]);
        $model = self::ACCOUNTS[$accountType] ?? null;
        $account = $model ? $model::where('email', $data['email'])->first() : null;
        // Seller staff sign in through the seller reset flow, but have their own
        // reset-code account type so an invite cannot be used for an owner account.
        $codeAccountType = $accountType;
        if ($accountType === 'seller' && !$account) {
            $account = SellerStaff::where('email', $data['email'])->first();
            $codeAccountType = 'seller_staff';
        }
        if (!$account) return response()->json($this->passwordResets->failurePayload(PasswordResetCodeService::INVALID), 422);
        $status = $this->passwordResets->inspect($account, $codeAccountType, $data['code']);
        if ($status === PasswordResetCodeService::INVALID) return response()->json($this->passwordResets->failurePayload($status), 422);
        if ($this->suspended($account)) return response()->json(['message' => 'Your account is suspended. Please contact support.', 'code' => 'account_suspended'], 403);
        if ($status !== PasswordResetCodeService::VALID) return response()->json($this->passwordResets->failurePayload($status), 422);
        return response()->json(['message' => 'Code is valid.']);
    }

    private function suspended(object $account): bool
    {
        if ($account instanceof Customer) return $account->crm_status === 'suspended';
        if ($account instanceof Seller) return $account->status === 'suspended' || ! $account->is_active;
        if ($account instanceof SellerStaff) return $account->status === 'suspended' || ! $account->seller?->is_active || $account->seller?->status === 'suspended';
        return isset($account->is_active) && ! $account->is_active;
    }
}
