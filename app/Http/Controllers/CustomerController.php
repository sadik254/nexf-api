<?php

namespace App\Http\Controllers;

use App\Mail\CustomerVerificationCodeMail;
use App\Models\Customer;
use App\Models\Admin;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Models\CustomerVerificationCode;
use App\Models\PendingCustomerRegistration;
use App\Services\PasswordResetCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Uploadcare\Api;
use Uploadcare\Configuration;

class CustomerController extends Controller
{
    use \App\Http\Controllers\Concerns\HandlesEmailChange;
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
            'password' => ['required', 'string', 'min:4'],
            'profile_picture' => ['nullable', 'file', 'image', 'max:5120'],
        ]);
        $email = strtolower(trim($data['email']));
        $phone = $this->normalizedPhone($data['phone'] ?? null);
        if (($data['phone'] ?? null) !== null && $phone === null) {
            throw ValidationException::withMessages(['phone' => ['Enter a valid Bangladesh mobile number.']]);
        }
        if (Customer::where('email', $email)->exists()) {
            return response()->json(['message' => 'Email already taken.'], 422);
        }
        if ($phone !== null && Customer::where('phone', $phone)->exists()) {
            return response()->json(['message' => 'Phone already taken.'], 422);
        }

        $profileUrl = null;
        if ($request->hasFile('profile_picture')) {
            $configuration = Configuration::create(config('services.uploadcare.public_key'), config('services.uploadcare.secret_key'));
            $profileUrl = 'https://ucarecdn.com/' . (new Api($configuration))->uploader()->fromPath($request->file('profile_picture')->getPathname())->getUuid() . '/-/preview/';
        }

        // A repeated sign-up replaces the waiting details and invalidates its old code.
        PendingCustomerRegistration::updateOrCreate(['email' => $email], [
            'name' => trim($data['name']),
            'phone' => $phone,
            'password' => Hash::make($data['password']),
            'profile_picture' => $profileUrl,
        ]);
        $this->sendVerificationCode($email, 'email_verification');

        return response()->json([
            'message' => 'We emailed you a code. Your account is created once you verify it.',
        ], 202);
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
        $data = $request->validate(['email' => ['required', 'email'], 'code' => ['required', 'digits:6']]);
        $email = strtolower(trim($data['email']));
        $code = $this->getValidCode($email, 'email_verification', $data['code']);
        if (!$code) return response()->json(['message' => 'Invalid or expired code.'], 422);

        $claimed = DB::transaction(function () use ($email, $code) {
            $customer = Customer::query()->where('email', $email)->lockForUpdate()->first();
            $pending = $customer ? null : PendingCustomerRegistration::query()->where('email', $email)->lockForUpdate()->first();
            if (!$customer && !$pending) throw ValidationException::withMessages(['code' => ['Invalid or expired code.']]);

            if ($pending) {
                if ($pending->phone !== null && Customer::query()->where('phone', $pending->phone)->exists()) {
                    throw ValidationException::withMessages(['phone' => ['Phone already taken. Please register with a different number.']]);
                }
                $customer = new Customer([
                    'name' => $pending->name,
                    'email' => $pending->email,
                    'phone' => $pending->phone,
                    'profile_picture' => $pending->profile_picture,
                ]);
                // The pending row receives a hash at registration; never hash it twice.
                $customer->password = $pending->password;
                $customer->email_verified_at = now();
                $customer->save();
                $pending->delete();
            } elseif ($customer->email_verified_at === null) {
                $customer->forceFill(['email_verified_at' => now()])->save();
            }

            $phone = $this->normalizedPhone($customer->phone);
            $claimed = $phone === null ? 0 : Order::query()
                ->whereNull('customer_id')->where('is_guest', true)->where('shipping_phone', $phone)
                ->update(['customer_id' => $customer->id]);
            $code->forceFill(['used_at' => now(), 'customer_id' => $customer->id])->save();
            return $claimed;
        });

        return response()->json(['message' => 'Email verified successfully.', 'claimed_orders' => $claimed]);
    }

    /** Public signup hint. It exposes a count only, never order details. */
    public function pendingGuestOrderCount(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);
        $phone = $this->normalizedPhone($data['phone']);
        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => ['Enter a valid Bangladesh mobile number.']]);
        }

        return response()->json(['count' => Order::query()
            ->whereNull('customer_id')
            ->where('is_guest', true)
            ->where('shipping_phone', $phone)
            ->count()]);
    }

    public function resendVerificationEmail(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $email = strtolower(trim($data['email']));
        $customer = Customer::where('email', $email)->first();
        if ($customer?->email_verified_at !== null) return response()->json(['message' => 'Email already verified.']);
        if (!$customer && !PendingCustomerRegistration::where('email', $email)->exists()) {
            return response()->json(['message' => 'If that email exists, a code has been sent.']);
        }
        $this->sendVerificationCode($email, 'email_verification', $customer?->id);
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
            'password' => ['required', 'string', 'min:4', 'confirmed'],
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

    private function sendVerificationCode(string $email, string $type, ?int $customerId = null): void
    {
        CustomerVerificationCode::query()
            ->where('email', $email)
            ->where('type', $type)
            ->whereNull('used_at')
            ->delete();

        $code = (string) random_int(100000, 999999);

        $verification = CustomerVerificationCode::create([
            'customer_id' => $customerId,
            'email' => $email,
            'code' => $code,
            'type' => $type,
            'expires_at' => now()->addMinutes(self::CODE_EXPIRES_MINUTES),
        ]);

        Mail::to($email)->send(
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

    private function normalizedPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (str_starts_with($digits, '880')) $digits = substr($digits, 2);
        elseif (str_starts_with($digits, '1')) $digits = "0{$digits}";

        return preg_match('/^01[3-9]\d{8}$/', $digits) ? $digits : null;
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

        if ($request->has('email') && strcasecmp((string) $data['email'], (string) $customer->email) !== 0) {
            throw ValidationException::withMessages(['email' => ['Verify the new email to change it.']]);
        }

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

        $data = $request->validate(['current_password' => ['required', 'string'], 'new_password' => ['required', 'string', 'min:4', 'confirmed']]);
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

    protected function emailChangeKey(): string { return 'customer'; }
}
