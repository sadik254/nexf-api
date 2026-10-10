<?php

namespace App\Http\Controllers;

use App\Mail\SellerApprovedMail;
use App\Models\Admin;
use App\Models\Seller;
use App\Services\PasswordResetCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Uploadcare\Api;
use Uploadcare\Configuration;

class SellerController extends Controller
{
    public function __construct(private PasswordResetCodeService $passwordResets) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(1, min($perPage, 100));
        $search = (string) $request->query('search', '');
        $status = (string) $request->query('status', '');

        $period = $request->validate(['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $salesScope = function ($items) use ($period) {
            return $items->whereHas('order', function ($orders) use ($period) {
                $orders->where('status', '!=', 'cancelled');
                if (isset($period['from'])) $orders->where('created_at', '>=', $period['from'].' 00:00:00');
                if (isset($period['to'])) $orders->where('created_at', '<=', $period['to'].' 23:59:59');
            });
        };
        $query = Seller::query()->whereNull('roster_archived_at')
            ->withCount('products')
            ->withCount(['productReviews as review_count' => fn($reviews) => $reviews->where('reviews.status', 'approved')])
            ->withCount(['productReviews as positive_review_count' => fn($reviews) => $reviews->where('reviews.status', 'approved')->where('reviews.rating', '>=', 4)])
            ->withCount(['storeChats as customer_chat_count' => fn($chats) => $chats->whereHas('messages', fn($messages) => $messages->where('author_type', 'customer'))])
            ->withCount(['storeChats as replied_chat_count' => fn($chats) => $chats->whereHas('messages', fn($messages) => $messages->where('author_type', 'customer'))->whereHas('messages', fn($messages) => $messages->where('author_type', 'seller')->whereColumn('store_chat_messages.author_id', 'store_chats.seller_id'))])
            ->withAvg(['productReviews as rating_average' => fn($reviews) => $reviews->where('reviews.status', 'approved')], 'rating')
            ->withSum(['orderItems as lifetime_units_sold' => fn($items) => $items->where('fulfillment_status', 'delivered')->whereHas('order', fn($orders) => $orders->whereIn('status', ['delivered', 'completed']))], 'quantity')
            ->withSum(['orderItems as units_sold' => $salesScope], 'quantity')
            ->withSum(['orderItems as revenue' => $salesScope], 'line_subtotal')
            ->latest();

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

        $page = $query->paginate($perPage);
        foreach ($page->getCollection() as $seller) {
            $seller->setAttribute('lifetime_units_sold', (int) ($seller->lifetime_units_sold ?? 0));
            $seller->setAttribute('positive_rating_percentage', $seller->review_count > 0 ? round($seller->positive_review_count / $seller->review_count * 100, 1) : null);
            $seller->setAttribute('chat_response_percentage', $seller->customer_chat_count > 0 ? round($seller->replied_chat_count / $seller->customer_chat_count * 100, 1) : null);
        }
        if (isset($period['from'], $period['to'])) {
            $from = \Carbon\CarbonImmutable::parse($period['from'])->startOfDay();
            $to = \Carbon\CarbonImmutable::parse($period['to'])->endOfDay();
            $days = (int) $from->diffInDays($to->startOfDay()) + 1;
            $priorFrom = $from->subDays($days);$priorTo = $from->subSecond();
            $ids = $page->getCollection()->pluck('id');
            $base = \App\Models\OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereIn('order_items.seller_id', $ids)->whereNull('orders.deleted_at')->where('orders.status', '!=', 'cancelled');
            $prior = (clone $base)->whereBetween('orders.created_at', [$priorFrom, $priorTo])
                ->selectRaw('order_items.seller_id, SUM(order_items.line_subtotal) AS total')->groupBy('order_items.seller_id')->pluck('total', 'seller_id');
            $daily = (clone $base)->whereBetween('orders.created_at', [$from, $to])
                ->selectRaw('order_items.seller_id, DATE(orders.created_at) AS day, SUM(order_items.line_subtotal) AS total')
                ->groupBy('order_items.seller_id')->groupByRaw('DATE(orders.created_at)')->get()->groupBy('seller_id');
            foreach ($page->getCollection() as $seller) {
                $previous = (float) ($prior[$seller->id] ?? 0);$current = (float) ($seller->revenue ?? 0);
                $seller->setAttribute('previous_revenue', $previous);
                $seller->setAttribute('revenue_delta', $previous > 0 ? round(($current - $previous) / $previous * 100, 2) : null);
                $trend = ($daily[$seller->id] ?? collect())->sortBy('day')->map(fn($row) => ['date' => $row->day, 'amount' => (float) $row->total])->values()->all();
                $seller->setAttribute('revenue_daily', $trend);
            }
        }
        return response()->json(array_merge($page->toArray(), ['sales_period' => $period ?: null]));
    }

    public function show(Seller $seller): JsonResponse
    {
        return response()->json($seller);
    }
    public function adminSave(Request $request, ?Seller $seller = null): JsonResponse
    {
        abort_if($seller?->roster_archived_at !== null, 404);
        $actor = $request->user();
        abort_unless($actor instanceof Admin && in_array($actor->role, ['super_admin', 'admin'], true), 403);
        $request->merge([
            'store_name' => trim((string) $request->input('store_name')),
            'email' => strtolower(trim((string) $request->input('email'))),
            'sku_prefix' => strtoupper(trim((string) $request->input('sku_prefix'))),
        ]);
        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:255'],
            'sku_prefix' => ['required', 'regex:/^[A-Z0-9]{4}$/', \Illuminate\Validation\Rule::unique('sellers')->ignore($seller?->id)],
            'email' => ['required', 'email', 'max:255', \Illuminate\Validation\Rule::unique('sellers')->ignore($seller?->id)],
            'phone' => ['required', 'regex:/^01[3-9][0-9]{8}$/', \Illuminate\Validation\Rule::unique('sellers')->ignore($seller?->id)],
            'address_line' => ['required', 'string', 'max:255'],
            'address_area' => ['required', 'string', 'max:255'],
            'address_district' => ['required', 'string', 'max:255'],
            'commission_rate' => ['required', 'numeric', 'between:0,100'],
        ]);
        $data['store_address'] = implode(', ', [$data['address_line'], $data['address_area'], $data['address_district']]);
        $data['city'] = $data['address_district'];
        $saved = \Illuminate\Support\Facades\DB::transaction(function () use ($seller, $data, $actor) {
            if ($seller) {
                $locked = Seller::query()->lockForUpdate()->findOrFail($seller->id);
                if (strtolower((string) $locked->email) !== $data['email']) {
                    $data['email_verified_at'] = null;
                    $locked->tokens()->delete();
                }
                $locked->fill($data)->save();
                return $locked;
            }
            return Seller::create($data + [
                'store_slug' => $this->uniqueStoreSlug($data['store_name']),
                'password' => Str::random(64),
                'status' => 'approved', 'is_active' => true,
                'approved_by' => $actor->id, 'approved_at' => now(),
            ]);
        });
        return response()->json($saved, $seller ? 200 : 201);
    }

    public function archive(Request $request, Seller $seller): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof Admin && in_array($actor->role, ['super_admin', 'admin'], true), 403);
        \Illuminate\Support\Facades\DB::transaction(function () use ($seller) {
            $locked = Seller::query()->lockForUpdate()->findOrFail($seller->id);
            if ($locked->roster_archived_at) return;
            $locked->products()->update(['seller_id' => null, 'owner_unassigned' => true]);
            $locked->tokens()->delete();
            $locked->forceFill(['roster_archived_at' => now(), 'is_active' => false, 'status' => 'suspended'])->save();
        });
        return response()->json(['message' => 'Seller removed from the marketplace. Products remain unassigned and order history is preserved.']);
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

    public function updateProfile(Request $request): JsonResponse
    {
        $seller = $request->user();
        abort_unless($seller instanceof Seller, 403);
        $request->merge(array_filter([
            'seller_name' => $request->has('seller_name') ? trim((string) $request->input('seller_name')) : null,
            'email' => $request->has('email') ? strtolower(trim((string) $request->input('email'))) : null,
        ], fn ($value) => $value !== null));
        $data = $request->validate([
            'seller_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', \Illuminate\Validation\Rule::unique('sellers')->ignore($seller->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', \Illuminate\Validation\Rule::unique('sellers')->ignore($seller->id)],
            'image' => ['sometimes', 'file', 'image', 'max:5120'],
            'clear_image' => ['sometimes', 'boolean'],
        ]);
        if (!empty($data['clear_image'])) $data['seller_image'] = null;
        if ($request->hasFile('image')) $data['seller_image'] = app(\App\Services\MediaUploadService::class)->upload($request->file('image'));
        unset($data['image'], $data['clear_image']);
        $seller->fill($data)->save();
        return response()->json(['message' => 'Profile updated.', 'seller' => $seller]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        /** @var Seller|null $seller */
        $seller = $request->user();
        if (!$seller) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate(['current_password' => ['required', 'string'], 'new_password' => ['required', 'string', 'min:8', 'confirmed']]);
        $currentPassword = $data['current_password']; $newPassword = $data['new_password'];
        if ($currentPassword === $newPassword) {
            return response()->json(['message' => 'New password must be different from current password.'], 422);
        }
        if (!Hash::check($currentPassword, $seller->password ?? '')) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $seller->forceFill([
            'password' => Hash::make($newPassword),
        ])->save();
        $seller->tokens()->delete();

        return response()->json(['message' => 'Password updated successfully.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $seller = Seller::where('email', $data['email'])->first();
        if ($seller) $this->passwordResets->send($seller, 'seller');
        return response()->json(['message' => 'If that email exists, a code has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'code' => ['required', 'digits:6'], 'password' => ['required', 'string', 'min:8', 'confirmed']]);
        $seller = Seller::where('email', $data['email'])->first();
        if (!$seller || !$this->passwordResets->consume($seller, 'seller', $data['code'])) return response()->json(['message' => 'Invalid or expired code.'], 422);
        $seller->forceFill(['password' => $data['password']])->save(); $seller->tokens()->delete();
        return response()->json(['message' => 'Password reset successfully.']);
    }

    public function approve(Seller $seller, Request $request): JsonResponse
    {
        abort_if($seller->roster_archived_at !== null, 404);
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
