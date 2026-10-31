<?php

namespace App\Http\Controllers;

use App\Mail\StaffInvitedMail;
use App\Models\Admin;
use App\Models\Seller;
use App\Models\SellerStaff;
use App\Services\AccountSecurityMailService;
use App\Services\EmailChangeService;
use App\Services\MediaUploadService;
use App\Services\PasswordResetCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SellerStaffController extends Controller
{
    private function owner(Request $request): Seller
    {
        abort_unless(!$request->attributes->has('seller_staff') && $request->user() instanceof Seller, 403);
        return $request->user();
    }

    private function data(Request $request, bool $new = false): array
    {
        $data = $request->validate([
            'name' => [$new ? 'required' : 'sometimes', 'string', 'max:255'],
            'email' => [$new ? 'required' : 'sometimes', 'email', 'max:255', Rule::unique('seller_staff')->ignore($request->route('staff'))],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
        ]);
        if (isset($data['email']) && Seller::query()->where('email', strtolower($data['email']))->exists()) {
            throw ValidationException::withMessages(['email' => ['Email already taken.']]);
        }
        return $data;
    }

    public function index(Request $request): JsonResponse { return response()->json($this->owner($request)->staff()->latest()->get()); }

    public function store(Request $request): JsonResponse
    {
        $seller = $this->owner($request);
        $staff = $seller->staff()->create($this->forNewStaff($this->data($request, true)));
        if ($staff->status === 'invited') $this->invite($staff, $seller);
        return response()->json($staff, 201);
    }

    public function update(Request $request, SellerStaff $staff): JsonResponse
    {
        abort_unless($staff->seller_id === $this->owner($request)->id, 404);
        $this->save($staff, $this->data($request), $staff->seller);
        return response()->json($staff->fresh());
    }

    public function destroy(Request $request, SellerStaff $staff): JsonResponse
    {
        abort_unless($staff->seller_id === $this->owner($request)->id, 404);
        $staff->tokens()->delete(); $staff->delete();
        return response()->json(['message' => 'Staff removed.']);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        abort_unless($request->user() instanceof Admin, 403);
        return response()->json(SellerStaff::with('seller:id,store_name,store_slug')->when($request->query('seller_id'), fn ($query, $id) => $query->where('seller_id', $id))->latest()->paginate(25));
    }

    public function adminStore(Request $request, Seller $seller): JsonResponse
    {
        abort_unless($request->user() instanceof Admin, 403);
        $staff = $seller->staff()->create($this->forNewStaff($this->data($request, true)));
        if ($staff->status === 'invited') $this->invite($staff, $seller);
        return response()->json($staff->load('seller:id,store_name,store_slug'), 201);
    }

    public function adminUpdate(Request $request, SellerStaff $staff): JsonResponse
    {
        abort_unless($request->user() instanceof Admin, 403);
        $data = $this->data($request) + $request->validate(['seller_id' => ['sometimes', 'integer', 'exists:sellers,id']]);
        $seller = isset($data['seller_id']) ? Seller::findOrFail($data['seller_id']) : $staff->seller;
        $this->save($staff, $data, $seller);
        return response()->json($staff->fresh()->load('seller:id,store_name,store_slug'));
    }

    public function adminDestroy(Request $request, SellerStaff $staff): JsonResponse
    {
        abort_unless($request->user() instanceof Admin, 403);
        $staff->tokens()->delete(); $staff->delete();
        return response()->json(['message' => 'Staff removed.']);
    }

    private function forNewStaff(array $data): array
    {
        $data['status'] = ($data['status'] ?? 'active') === 'suspended' ? 'suspended' : 'invited';
        return $data;
    }

    private function save(SellerStaff $staff, array $data, Seller $seller): void
    {
        if (($data['status'] ?? null) === 'active' && $staff->password === null) {
            $data['status'] = 'invited';
            $staff->fill($data)->save();
            $this->invite($staff->fresh(), $seller);
            return;
        }
        $staff->fill($data)->save();
        if (($data['status'] ?? null) === 'suspended') $staff->tokens()->delete();
    }

    private function invite(SellerStaff $staff, Seller $seller): void
    {
        $code = app(PasswordResetCodeService::class)->issue($staff, 'seller_staff', 60 * 24 * 7);
        Mail::to($staff->email)->send(new StaffInvitedMail($staff->name, $staff->email, $seller->store_name, $code));
    }

    public function me(Request $request): JsonResponse
    {
        $staff = $request->attributes->get('seller_staff'); abort_unless($staff instanceof SellerStaff, 403);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:255'], 'email' => ['sometimes', 'email', 'max:255'], 'phone' => ['sometimes', 'nullable', 'string', 'max:32'], 'image' => ['sometimes', 'file', 'image', 'max:5120'], 'image_media_id' => ['sometimes', 'nullable', 'integer', 'exists:media_assets,id'], 'image_url' => ['sometimes', 'nullable', 'url', 'max:2048'], 'clear_image' => ['sometimes', 'boolean']]);
        if (isset($data['email']) && strcasecmp($data['email'], $staff->email) !== 0) throw ValidationException::withMessages(['email' => ['Verify the new email to change it.']]);
        unset($data['email']);
        if (!empty($data['clear_image'])) $data['image'] = null;
        if ($request->hasFile('image')) $data['image'] = app(MediaUploadService::class)->upload($request->file('image'));
        elseif (!empty($data['image_media_id']) || !empty($data['image_url'])) $data['image'] = app(\App\Services\LibraryMediaService::class)->image($data['image_media_id'] ?? null, $data['image_url'] ?? null, $staff->seller);
        unset($data['image_media_id'], $data['image_url'], $data['clear_image']); $staff->update($data);
        return response()->json(['message' => 'Profile updated.', 'staff' => $staff->fresh()]);
    }

    public function password(Request $request): JsonResponse
    {
        $staff = $request->attributes->get('seller_staff'); abort_unless($staff instanceof SellerStaff, 403);
        $data = $request->validate(['current_password' => ['required', 'string'], 'new_password' => ['required', 'string', 'min:8', 'confirmed']]);
        if (!Hash::check($data['current_password'], $staff->password ?? '')) return response()->json(['message' => 'Current password is incorrect.'], 422);
        $staff->update(['password' => $data['new_password']]); $staff->tokens()->delete();
        app(AccountSecurityMailService::class)->passwordChanged($staff, 'settings', $request->ip());
        return response()->json(['message' => 'Password updated successfully.']);
    }

    public function emailCurrent(Request $request, EmailChangeService $service): JsonResponse { $staff = $request->attributes->get('seller_staff'); abort_unless($staff instanceof SellerStaff, 403); return response()->json($service->sendCurrent($staff)); }
    public function emailVerifyCurrent(Request $request, EmailChangeService $service): JsonResponse { $staff = $request->attributes->get('seller_staff'); $data = $request->validate(['code' => ['required', 'digits:6']]); return response()->json($service->verifyCurrent($staff, $data['code'])); }
    public function emailRequest(Request $request, EmailChangeService $service): JsonResponse { $staff = $request->attributes->get('seller_staff'); $data = $request->validate(['email' => ['required', 'email', 'max:255']]); return response()->json($service->request($staff, $data['email'])); }
    public function emailVerifyNew(Request $request, EmailChangeService $service): JsonResponse { $staff = $request->attributes->get('seller_staff'); $data = $request->validate(['code' => ['required', 'digits:6']]); return response()->json(['message' => 'Email updated.', 'staff' => $service->verifyNew($staff, $data['code'])]); }
    public function emailResend(Request $request, EmailChangeService $service): JsonResponse { $staff = $request->attributes->get('seller_staff'); $data = $request->validate(['step' => ['required', 'in:current,new']]); return response()->json($service->resend($staff, $data['step'])); }
}
