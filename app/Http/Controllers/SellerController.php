<?php

namespace App\Http\Controllers;

use App\Mail\SellerApprovedMail;
use App\Models\Admin;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Uploadcare\Api;
use Uploadcare\Configuration;

class SellerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(1, min($perPage, 100));
        $search = (string) $request->query('search', '');
        $status = (string) $request->query('status', '');

        $query = Seller::query()->latest();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('seller_name', 'like', "%{$search}%")
                    ->orWhere('store_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        return response()->json($query->paginate($perPage));
    }

    public function show(Seller $seller): JsonResponse
    {
        return response()->json($seller);
    }
    public function onboard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seller_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'store_name' => ['required', 'string', 'max:255'],
            'store_address' => ['nullable', 'string'],
            'store_logo' => ['nullable', 'file', 'image', 'max:5120'],
            'store_image' => ['nullable', 'file', 'image', 'max:5120'],
            'seller_image' => ['nullable', 'file', 'image', 'max:5120'],
            'kyc_type' => ['required', 'in:nid,passport'],
            'kyc_number' => ['required', 'string', 'max:64'],
            'kyc_document' => ['required', 'file', 'max:10240'],
            'product_category' => ['required', 'string', 'max:255'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:32'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
        ]);

        if (Seller::where('email', $data['email'])->exists()) {
            return response()->json(['message' => 'Email already taken.'], 422);
        }
        if (!empty($data['phone']) && Seller::where('phone', $data['phone'])->exists()) {
            return response()->json(['message' => 'Phone already taken.'], 422);
        }

        $configuration = Configuration::create(
            config('services.uploadcare.public_key'),
            config('services.uploadcare.secret_key')
        );
        $api = new Api($configuration);

        $storeLogoUrl = null;
        if ($request->hasFile('store_logo')) {
            $file = $api->uploader()->fromPath(
                $request->file('store_logo')->getPathname()
            );
            $storeLogoUrl = "https://ucarecdn.com/{$file->getUuid()}/-/preview/";
        }

        $storeImageUrl = null;
        if ($request->hasFile('store_image')) {
            $file = $api->uploader()->fromPath(
                $request->file('store_image')->getPathname()
            );
            $storeImageUrl = "https://ucarecdn.com/{$file->getUuid()}/-/preview/";
        }

        $sellerImageUrl = null;
        if ($request->hasFile('seller_image')) {
            $file = $api->uploader()->fromPath(
                $request->file('seller_image')->getPathname()
            );
            $sellerImageUrl = "https://ucarecdn.com/{$file->getUuid()}/-/preview/";
        }

        $kycDocumentUrl = null;
        if ($request->hasFile('kyc_document')) {
            $file = $api->uploader()->fromPath(
                $request->file('kyc_document')->getPathname()
            );
            $kycDocumentUrl = "https://ucarecdn.com/{$file->getUuid()}/-/preview/";
        }

        $storeSlug = $this->uniqueStoreSlug($data['store_name']);

        $seller = Seller::create([
            'seller_name' => $data['seller_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'store_name' => $data['store_name'],
            'store_slug' => $storeSlug,
            'store_address' => $data['store_address'] ?? null,
            'store_logo' => $storeLogoUrl,
            'store_image' => $storeImageUrl,
            'seller_image' => $sellerImageUrl,
            'kyc_type' => $data['kyc_type'],
            'kyc_number' => $data['kyc_number'],
            'kyc_document_url' => $kycDocumentUrl ?? '',
            'product_category' => $data['product_category'],
            'support_email' => $data['support_email'] ?? null,
            'support_phone' => $data['support_phone'] ?? null,
            'city' => $data['city'] ?? null,
            'country' => $data['country'] ?? null,
            'status' => 'pending',
            'is_active' => false,
        ]);

        return response()->json([
            'message' => 'Seller onboarding submitted and pending approval.',
            'seller' => $seller,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $seller = Seller::where('email', $data['email'])->first();
        if (!$seller || !Hash::check($data['password'], $seller->password ?? '')) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if ($seller->status !== 'approved' || !$seller->is_active) {
            return response()->json(['message' => 'Account is not approved.'], 403);
        }

        $seller->forceFill([
            'login_ip' => $request->ip(),
            'last_login_at' => now(),
        ])->save();

        $token = $seller->createToken('seller-api', ['seller:basic']);

        return response()->json([
            'token' => $token->plainTextToken,
            'seller' => $seller,
        ]);
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

    public function updatePassword(Request $request): JsonResponse
    {
        /** @var Seller|null $seller */
        $seller = $request->user();
        if (!$seller) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $currentPassword = (string) $request->input('current_password', '');
        $newPassword = (string) $request->input('new_password', '');

        if ($currentPassword === '' || $newPassword === '') {
            return response()->json(['message' => 'Current and new password are required.'], 422);
        }
        if (strlen($newPassword) < 8) {
            return response()->json(['message' => 'New password must be at least 8 characters.'], 422);
        }
        if ($currentPassword === $newPassword) {
            return response()->json(['message' => 'New password must be different from current password.'], 422);
        }
        if (!Hash::check($currentPassword, $seller->password ?? '')) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $seller->forceFill([
            'password' => Hash::make($newPassword),
        ])->save();

        return response()->json(['message' => 'Password updated successfully.']);
    }

    public function approve(Seller $seller, Request $request): JsonResponse
    {
        /** @var Admin|null $actor */
        $actor = $request->user();
        if (!$actor || !in_array($actor->role, ['super_admin', 'admin'], true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $passwordPlain = bin2hex(random_bytes(4));

        $seller->forceFill([
            'status' => 'approved',
            'rejection_reason' => null,
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'password' => $passwordPlain,
            'is_active' => true,
        ])->save();

        Mail::to($seller->email)->send(new SellerApprovedMail($seller, $passwordPlain));

        return response()->json(['message' => 'Seller approved and credentials sent.']);
    }

    public function reject(Seller $seller, Request $request): JsonResponse
    {
        /** @var Admin|null $actor */
        $actor = $request->user();
        if (!$actor || !in_array($actor->role, ['super_admin', 'admin'], true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'rejection_reason' => ['required', 'string'],
        ]);

        $seller->forceFill([
            'status' => 'rejected',
            'rejection_reason' => $data['rejection_reason'],
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'is_active' => false,
        ])->save();

        return response()->json(['message' => 'Seller rejected.']);
    }

    private function uniqueStoreSlug(string $storeName): string
    {
        $base = Str::slug($storeName);
        $slug = $base !== '' ? $base : Str::random(8);
        $counter = 1;

        while (Seller::where('store_slug', $slug)->exists()) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
