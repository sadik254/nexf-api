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
        if (!$account || !$this->passwordResets->check($account, $codeAccountType, $data['code'])) {
            return response()->json(['message' => 'Invalid or expired code.', 'errors' => ['code' => ['Invalid or expired code.']]], 422);
        }
        return response()->json(['message' => 'Code is valid.']);
    }
}
