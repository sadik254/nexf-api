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
            'badges' => ['required', 'array', 'max:8'],
            'badges.*.icon' => ['required', Rule::in(['BadgeCheck', 'Crown', 'Truck', 'RefreshCw', 'ShieldCheck'])],
            'badges.*.label' => ['required', 'string', 'max:120'],
            'badges.*.tone' => ['required', Rule::in(['blue', 'pink', 'violet', 'emerald', 'amber', 'sky'])],
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
