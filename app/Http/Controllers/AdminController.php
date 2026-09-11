<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Uploadcare\Api;
use Uploadcare\Configuration;
use App\Mail\AdminCreatedMail;
use App\Services\PasswordResetCodeService;

class AdminController extends Controller
{
    public function __construct(private PasswordResetCodeService $passwordResets) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(1, min($perPage, 100));
        $search = (string) $request->query('search', '');

        $query = Admin::query()->latest();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return response()->json($query->paginate($perPage));
    }

    public function show(Admin $admin): JsonResponse
    {
        return response()->json($admin);
    }
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $admin = Admin::where('email', $data['email'])->first();
        if (!$admin || !Hash::check($data['password'], $admin->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if (!$admin->is_active) {
            return response()->json(['message' => 'Account is inactive.'], 403);
        }

        $admin->forceFill([
            'login_ip' => $request->ip(),
            'last_login_at' => now(),
        ])->save();

        $token = $admin->createToken('admin-api', $this->abilitiesForRole($admin->role));

        return response()->json([
            'token' => $token->plainTextToken,
            'admin' => $admin,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var Admin|null $actor */
        $actor = $request->user();
        if (!$actor || $actor->role !== 'super_admin') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string'],
            'image' => ['nullable', 'file', 'image', 'max:5120'],
            'role' => ['nullable', 'in:admin,moderator,editor'],
        ]);

        if (Admin::where('email', $data['email'])->exists()) {
            return response()->json(['message' => 'Email already taken.'], 422);
        }
        if (!empty($data['phone']) && Admin::where('phone', $data['phone'])->exists()) {
            return response()->json(['message' => 'Phone already taken.'], 422);
        }

        $imageUrl = null;
        if ($request->hasFile('image')) {
            $configuration = Configuration::create(
                config('services.uploadcare.public_key'),
                config('services.uploadcare.secret_key')
            );
            $api = new Api($configuration);

            $file = $api->uploader()->fromPath(
                $request->file('image')->getPathname()
            );

            $imageUrl = "https://ucarecdn.com/{$file->getUuid()}/-/preview/";
        }

        $passwordPlain = bin2hex(random_bytes(4));

        $admin = Admin::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $passwordPlain,
            'address' => $data['address'] ?? null,
            'image' => $imageUrl,
            'role' => $data['role'] ?? 'moderator',
        ]);

        Mail::to($admin->email)->send(new AdminCreatedMail($admin, $passwordPlain));

        return response()->json([
            'message' => 'Admin created successfully.',
            'admin' => $admin,
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->bearerToken();
        if (!$token) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $accessToken->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function updateAdmin(Request $request, Admin $admin): JsonResponse
    {
        /** @var Admin|null $actor */
        $actor = $request->user();
        if (!$actor || $actor->role !== 'super_admin') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:32'],
            'address' => ['sometimes', 'string'],
            'image' => ['sometimes', 'file', 'image', 'max:5120'],
            'role' => ['sometimes', 'in:admin,moderator,editor,super_admin'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($request->filled('email')) {
            $emailExists = Admin::where('email', $data['email'])
                ->where('id', '!=', $admin->id)
                ->exists();
            if ($emailExists) {
                return response()->json(['message' => 'Email already taken.'], 422);
            }
        }

        if ($request->filled('phone')) {
            $phoneExists = Admin::where('phone', $data['phone'])
                ->where('id', '!=', $admin->id)
                ->exists();
            if ($phoneExists) {
                return response()->json(['message' => 'Phone already taken.'], 422);
            }
        }

        if ($request->filled('role')) {
            $requestedRole = $data['role'];
            if ($admin->role === 'super_admin' && $requestedRole !== 'super_admin') {
                return response()->json(['message' => 'Cannot change role of super admin.'], 422);
            }
            if ($admin->role !== 'super_admin' && $requestedRole === 'super_admin') {
                return response()->json(['message' => 'Super admin cannot be reassigned.'], 422);
            }
        }

        $imageUrl = $admin->image;
        if ($request->hasFile('image')) {
            $configuration = Configuration::create(
                config('services.uploadcare.public_key'),
                config('services.uploadcare.secret_key')
            );
            $api = new Api($configuration);

            $file = $api->uploader()->fromPath(
                $request->file('image')->getPathname()
            );

            $imageUrl = "https://ucarecdn.com/{$file->getUuid()}/-/preview/";
        }

        $admin->fill([
            'name' => $data['name'] ?? $admin->name,
            'email' => $data['email'] ?? $admin->email,
            'phone' => $data['phone'] ?? $admin->phone,
            'address' => $data['address'] ?? $admin->address,
            'image' => $imageUrl,
            'role' => $data['role'] ?? $admin->role,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $admin->is_active,
        ])->save();

        return response()->json([
            'message' => 'Admin updated successfully.',
            'admin' => $admin,
        ]);
    }

    public function destroy(Admin $admin, Request $request): JsonResponse
    {
        /** @var Admin|null $actor */
        $actor = $request->user();
        if (!$actor || $actor->role !== 'super_admin') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($admin->role === 'super_admin') {
            return response()->json(['message' => 'Cannot delete super admin.'], 422);
        }

        $admin->delete();

        return response()->json(['message' => 'Admin soft-deleted successfully.']);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        /** @var Admin|null $admin */
        $admin = $request->user();
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:32'],
            'address' => ['sometimes', 'string'],
            'image' => ['sometimes', 'file', 'image', 'max:5120'],
        ]);

        if ($request->filled('email')) {
            $emailExists = Admin::where('email', $data['email'])
                ->where('id', '!=', $admin->id)
                ->exists();
            if ($emailExists) {
                return response()->json(['message' => 'Email already taken.'], 422);
            }
        }

        if ($request->filled('phone')) {
            $phoneExists = Admin::where('phone', $data['phone'])
                ->where('id', '!=', $admin->id)
                ->exists();
            if ($phoneExists) {
                return response()->json(['message' => 'Phone already taken.'], 422);
            }
        }

        $imageUrl = $admin->image;
        if ($request->hasFile('image')) {
            $configuration = Configuration::create(
                config('services.uploadcare.public_key'),
                config('services.uploadcare.secret_key')
            );
            $api = new Api($configuration);

            $file = $api->uploader()->fromPath(
                $request->file('image')->getPathname()
            );

            $imageUrl = "https://ucarecdn.com/{$file->getUuid()}/-/preview/";
        }

        $admin->fill([
            'name' => $data['name'] ?? $admin->name,
            'email' => $data['email'] ?? $admin->email,
            'phone' => $data['phone'] ?? $admin->phone,
            'address' => $data['address'] ?? $admin->address,
            'image' => $imageUrl,
        ])->save();

        return response()->json([
            'message' => 'Profile updated successfully.',
            'admin' => $admin,
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        /** @var Admin|null $admin */
        $admin = $request->user();
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate(['current_password' => ['required', 'string'], 'new_password' => ['required', 'string', 'min:8', 'confirmed']]);
        $currentPassword = $data['current_password']; $newPassword = $data['new_password'];
        if ($currentPassword === $newPassword) {
            return response()->json(['message' => 'New password must be different from current password.'], 422);
        }
        if (!Hash::check($currentPassword, $admin->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $admin->forceFill([
            'password' => Hash::make($newPassword),
        ])->save();
        $admin->tokens()->delete();

        return response()->json(['message' => 'Password updated successfully.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $admin = Admin::where('email', $data['email'])->first();
        if ($admin) $this->passwordResets->send($admin, 'admin');
        return response()->json(['message' => 'If that email exists, a code has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'code' => ['required', 'digits:6'], 'password' => ['required', 'string', 'min:8', 'confirmed']]);
        $admin = Admin::where('email', $data['email'])->first();
        if (!$admin || !$this->passwordResets->consume($admin, 'admin', $data['code'])) return response()->json(['message' => 'Invalid or expired code.'], 422);
        $admin->forceFill(['password' => $data['password']])->save(); $admin->tokens()->delete();
        return response()->json(['message' => 'Password reset successfully.']);
    }

    private function abilitiesForRole(string $role): array
    {
        $base = ['admin:basic'];

        if ($role === 'super_admin') {
            return array_merge($base, [
                'admin:manage-admins',
                'admin:customers',
                'admin:orders',
                'admin:products',
                'admin:payment-methods',
                'admin:shipping-methods',
                'admin:coupons',
            ]);
        }

        if ($role === 'admin') {
            return array_merge($base, [
                'admin:customers',
                'admin:orders',
                'admin:products',
            ]);
        }

        if ($role === 'moderator') {
            return array_merge($base, [
                'admin:customers',
            ]);
        }

        return $base;
    }
}
