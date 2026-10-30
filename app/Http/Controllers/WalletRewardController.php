<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use App\Services\WalletRewardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletRewardController extends Controller
{
    public function show(WalletRewardService $rewards): JsonResponse
    {
        return response()->json($rewards->rewards());
    }

    public function save(Request $request, WalletRewardService $rewards): JsonResponse
    {
        $data = $request->validate([
            'cashback_enabled' => ['required', 'boolean'],
            'cashback_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'cashback_min_order' => ['nullable', 'numeric', 'min:0'],
            'cashback_max_amount' => ['nullable', 'numeric', 'min:0'],
            'review_enabled' => ['required', 'boolean'],
            'review_amount' => ['required', 'numeric', 'min:0'],
            'referral_enabled' => ['required', 'boolean'],
            'referral_amount' => ['required', 'numeric', 'min:0'],
        ]);
        $data['cashback_min_order'] = $data['cashback_min_order'] ?? 0;
        $data['cashback_max_amount'] = $data['cashback_max_amount'] ?? 0;

        SiteSetting::updateOrCreate(['key' => 'wallet_rewards'], ['payload' => $data]);

        return $this->show($rewards);
    }
}
