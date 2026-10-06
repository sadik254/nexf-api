<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\HomepageTrustBadge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HomepageTrustBadgeController extends Controller
{
    public function publicIndex(): JsonResponse
    {
        return response()->json(HomepageTrustBadge::where('hidden', false)->orderBy('sort_order')->get());
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeManager($request);
        return response()->json(HomepageTrustBadge::orderBy('sort_order')->get());
    }

    public function replace(Request $request): JsonResponse
    {
        $this->authorizeManager($request);
        $data = $request->validate([
            'badges' => ['present', 'array', 'max:8'],
            'badges.*.icon' => ['required', Rule::in(['BadgeCheck', 'Crown', 'Truck', 'RefreshCw', 'Undo2', 'ShieldCheck', 'Lock', 'CreditCard', 'Wallet', 'BadgePercent', 'Gift', 'Package', 'Clock', 'Zap', 'Headset', 'Award', 'Star', 'ThumbsUp', 'Heart', 'Smile', 'Leaf', 'MapPin', 'Store'])],
            'badges.*.label' => ['required', 'string', 'max:120'],
            'badges.*.tone' => ['required', Rule::in(['slate', 'gray', 'zinc', 'neutral', 'stone', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose'])],
            'badges.*.hidden' => ['sometimes', 'boolean'],
        ]);
        DB::transaction(function () use ($data) {
            HomepageTrustBadge::query()->delete();
            foreach ($data['badges'] as $index => $badge) {
                HomepageTrustBadge::create([...$badge, 'sort_order' => $index]);
            }
        });
        return $this->index($request);
    }

    private function authorizeManager(Request $request): void
    {
        $admin = $request->user();
        abort_unless($admin instanceof Admin && $admin->role === 'super_admin', 403);
    }
}
