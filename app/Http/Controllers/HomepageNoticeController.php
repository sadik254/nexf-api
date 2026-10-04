<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\HomepageNotice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomepageNoticeController extends Controller
{
    public function publicIndex(): JsonResponse
    {
        return response()->json(HomepageNotice::where('enabled', true)->orderBy('sort_order')->orderBy('id')->get());
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeManager($request);
        return response()->json(HomepageNotice::orderBy('sort_order')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManager($request);
        $data = $request->validate(['text' => ['required', 'string', 'max:1000'], 'enabled' => ['sometimes', 'boolean'], 'sort_order' => ['sometimes', 'integer', 'min:0']]);
        $data['sort_order'] ??= (int) HomepageNotice::max('sort_order') + 1;
        return response()->json(HomepageNotice::create($data), 201);
    }

    public function update(Request $request, HomepageNotice $notice): JsonResponse
    {
        $this->authorizeManager($request);
        $data = $request->validate(['text' => ['sometimes', 'required', 'string', 'max:1000'], 'enabled' => ['sometimes', 'boolean'], 'sort_order' => ['sometimes', 'integer', 'min:0']]);
        $notice->update($data);
        return response()->json($notice->fresh());
    }

    public function destroy(Request $request, HomepageNotice $notice): JsonResponse
    {
        $this->authorizeManager($request);
        $notice->delete();
        return response()->json(['message' => 'Notice deleted.']);
    }

    private function authorizeManager(Request $request): void
    {
        $admin = $request->user();
        abort_unless($admin instanceof Admin && $admin->role === 'super_admin', 403);
    }
}
