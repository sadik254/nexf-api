<?php

namespace App\Http\Controllers;

use App\Mail\CustomerVerificationCodeMail;
use App\Models\Customer;
use App\Models\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Models\CustomerVerificationCode;
use App\Services\PasswordResetCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Uploadcare\Api;
use Uploadcare\Configuration;

class CustomerController extends Controller
{
    private const CODE_EXPIRES_MINUTES = 10;

    public function __construct(private PasswordResetCodeService $passwordResets) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(1, min($perPage, 100));
        $search = trim((string) $request->query('search', ''));

        $customers = Customer::query()->whereNull('roster_archived_at')
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->withCount('orders')
            ->withCount(['orders as received_orders_count' => fn ($orders) => $orders->whereIn('status', ['delivered', 'completed'])])
            ->withCount(['orders as failed_orders_count' => fn ($orders) => $orders->whereIn('status', ['cancelled', 'refused'])])
            ->withCount(['orders as pending_orders_count' => fn ($orders) => $orders->whereNotIn('status', ['delivered', 'completed', 'cancelled', 'refused'])])
            ->withSum(['orders as total_spent' => fn ($orders) => $orders->where('status', '!=', 'cancelled')], 'total')
            ->latest()
            ->paginate($perPage);

        return response()->json($customers);
    }

    public function adminSaveContact(Request $request, ?Customer $customer = null): JsonResponse
    {
        abort_unless($request->user() instanceof Admin, 403);
        abort_if($customer?->roster_archived_at !== null, 404);
        $id = $customer?->id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'regex:/^01[0-9]{9}$/', Rule::unique('customers', 'phone')->ignore($id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('customers', 'email')->ignore($id)],
            'street' => ['nullable', 'string', 'max:500'],
            'area' => ['nullable', 'string', 'max:150'],
            'district' => ['nullable', 'string', 'max:100'],
            'crm_status' => ['required', Rule::in(['new', 'active', 'vip'])],
            'is_risky' => ['sometimes', 'boolean'],
        ]);
        $created = $customer === null;
        $result = DB::transaction(function () use ($customer, $data) {
            $record = $customer ? Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail() : new Customer();
            abort_if($record->roster_archived_at !== null, 404);
            $email = $data['email'] ?? null;
            if ($record->exists && $record->email !== $email) {
                $record->forceFill(['email_verified_at' => null]);
                $record->tokens()->delete();
            }
            $record->fill(['name' => trim($data['name']), 'phone' => $data['phone'], 'email' => $email,
                'contact_street' => $data['street'] ?? null, 'contact_area' => $data['area'] ?? null,
                'contact_district' => $data['district'] ?? null, 'crm_status' => $data['crm_status'],
                'is_risky' => $data['is_risky'] ?? $record->is_risky ?? false]);
            // A contact record grants no verified account or known login password.
            if (!$record->exists) $record->password = Str::random(64);
            $record->save();
            return $record;
        });
        return response()->json($result, $created ? 201 : 200);
    }

    public function adminDeleteContact(Request $request, Customer $customer): JsonResponse
    {
        abort_unless($request->user() instanceof Admin, 403);
        abort_if($customer->roster_archived_at !== null, 404);
        // Hide the roster entry while preserving the customer's actual orders and wallet.
        $customer->forceFill(['roster_archived_at' => now()])->save();
        return response()->json(['message' => 'Customer removed from the roster.']);
    }

    public function show(Customer $customer): JsonResponse
    {
        return response()->json($customer);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'string', 'min:8'],
            'profile_picture' => ['nullable', 'file', 'image', 'max:5120'],
        ]);

        if (Customer::where('email', $data['email'])->exists()) {
            return response()->json(['message' => 'Email already taken.'], 422);
        }
        if (!empty($data['phone']) && Customer::where('phone', $data['phone'])->exists()) {
            return response()->json(['message' => 'Phone already taken.'], 422);
        }
        $profileUrl = null;
        if ($request->hasFile('profile_picture')) {
            $configuration = Configuration::create(
                config('services.uploadcare.public_key'),
                config('services.uploadcare.secret_key')
            );
            $api = new Api($configuration);

            $file = $api->uploader()->fromPath(
                $request->file('profile_picture')->getPathname()
            );

            $profileUrl = "https://ucarecdn.com/{$file->getUuid()}/-/preview/";
        }

        $customer = Customer::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'profile_picture' => $profileUrl,
        ]);

        $this->sendVerificationCode($customer, 'email_verification');

        return response()->json([
            'message' => 'Customer created. Please verify your email.',
            'customer' => $customer,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $customer = Customer::where('email', $data['email'])->first();

        if (!$customer || !Hash::check($data['password'], $customer->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if ($customer->email_verified_at === null) {
            return response()->json(['message' => 'please verify your email'], 403);
        }

        $token = $customer->createToken('customer-api', ['customer:basic']);

        return response()->json([
            'token' => $token->plainTextToken,
            'customer' => $customer,
        ]);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
        ]);

        $customer = Customer::where('email', $data['email'])->first();
        if (!$customer) {
            return response()->json(['message' => 'Invalid code.'], 422);
        }

        $code = $this->getValidCode($data['email'], 'email_verification', $data['code']);
        if (!$code) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $customer->forceFill(['email_verified_at' => now()])->save();
        $code->forceFill(['used_at' => now()])->save();

        return response()->json(['message' => 'Email verified successfully.']);
    }

    public function resendVerificationEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $customer = Customer::where('email', $data['email'])->first();
        if (!$customer) {
            return response()->json(['message' => 'If that email exists, a code has been sent.']);
        }

        if ($customer->email_verified_at !== null) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $this->sendVerificationCode($customer, 'email_verification');

        return response()->json(['message' => 'Verification code resent.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $customer = Customer::where('email', $data['email'])->first();
        if ($customer) {
            $this->passwordResets->send($customer, 'customer');
        }

        return response()->json([
            'message' => 'If that email exists, a code has been sent.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $customer = Customer::where('email', $data['email'])->first();
        if (!$customer) {
            return response()->json(['message' => 'Invalid code.'], 422);
        }

        if (!$this->passwordResets->consume($customer, 'customer', $data['code'])) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $customer->forceFill([
            'password' => $data['password'],
        ])->save();

        $customer->tokens()->delete();

        return response()->json(['message' => 'Password reset successfully.']);
    }

    private function sendVerificationCode(Customer $customer, string $type): void
    {
        CustomerVerificationCode::query()
            ->where('email', $customer->email)
            ->where('type', $type)
            ->whereNull('used_at')
            ->delete();

        $code = (string) random_int(100000, 999999);

        $verification = CustomerVerificationCode::create([
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'code' => $code,
            'type' => $type,
            'expires_at' => now()->addMinutes(self::CODE_EXPIRES_MINUTES),
        ]);

        Mail::to($customer->email)->send(
            new CustomerVerificationCodeMail($verification->code, $verification->type)
        );
    }

    private function getValidCode(string $email, string $type, string $code): ?CustomerVerificationCode
    {
        return CustomerVerificationCode::query()
            ->where('email', $email)
            ->where('type', $type)
            ->where('code', $code)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();
    }

    public function update(Request $request)
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:32'],
            'profile_picture' => ['sometimes', 'file', 'image', 'max:5120'],
            'remove_profile_picture' => ['sometimes', 'boolean'],
        ]);

        if ($request->filled('email')) {
            $emailExists = Customer::where('email', $data['email'])
                ->where('id', '!=', $customer->id)
                ->exists();
            if ($emailExists) {
                return response()->json(['message' => 'Email already taken.'], 422);
            }
        }

        if ($request->filled('phone')) {
            $phoneExists = Customer::where('phone', $data['phone'])
                ->where('id', '!=', $customer->id)
                ->exists();
            if ($phoneExists) {
                return response()->json(['message' => 'Phone already taken.'], 422);
            }
        }

        $profileUrl = $request->boolean('remove_profile_picture') ? null : $customer->profile_picture;
        if ($request->hasFile('profile_picture')) {
            $configuration = Configuration::create(
                config('services.uploadcare.public_key'),
                config('services.uploadcare.secret_key')
            );
            $api = new Api($configuration);

            $file = $api->uploader()->fromPath(
                $request->file('profile_picture')->getPathname()
            );

            $profileUrl = "https://ucarecdn.com/{$file->getUuid()}/-/preview/";
        }

        $customer->fill([
            'name' => $data['name'] ?? $customer->name,
            'email' => $data['email'] ?? $customer->email,
            'phone' => $data['phone'] ?? $customer->phone,
            'profile_picture' => $profileUrl,
        ])->save();

        return response()->json([
            'message' => 'Profile updated successfully.',
            'customer' => $customer,
        ]);
    }

    public function updatePassword(Request $request)
    {
        /** @var Customer|null $customer */
        $customer = $request->user();
        if (!$customer) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate(['current_password' => ['required', 'string'], 'new_password' => ['required', 'string', 'min:8', 'confirmed']]);
        $currentPassword = $data['current_password']; $newPassword = $data['new_password'];
        if ($currentPassword === $newPassword) {
            return response()->json(['message' => 'New password must be different from current password.'], 422);
        }
        if (!Hash::check($currentPassword, $customer->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $customer->forceFill([
            'password' => Hash::make($newPassword),
        ])->save();
        $customer->tokens()->delete();

        return response()->json(['message' => 'Password updated successfully.']);
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
}
