<?php

namespace App\Services;

use App\Mail\PasswordResetCodeMail;
use App\Models\PasswordResetCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class PasswordResetCodeService
{
    private const EXPIRES_MINUTES = 10;
    public const VALID = 'valid';
    public const INVALID = 'invalid';
    public const USED = 'code_used';
    public const EXPIRED = 'code_expired';
    public const PASSWORD_ALREADY_SET = 'password_already_set';

    /**
     * Issue a one-time code for an account invitation. Normal password-reset
     * codes continue to use the short expiry in send().
     */
    public function issue(Model $account, string $accountType, int $minutes = 60 * 24 * 7): string
    {
        $this->replaceActiveCodes($account, $accountType);
        $code = (string) random_int(100000, 999999);
        PasswordResetCode::create(['account_type' => $accountType, 'account_id' => $account->getKey(), 'email' => $account->email, 'code_hash' => Hash::make($code), 'is_invite' => true, 'expires_at' => now()->addMinutes($minutes)]);
        return $code;
    }

    public function send(Model $account, string $accountType): void
    {
        $this->replaceActiveCodes($account, $accountType);
        $code = (string) random_int(100000, 999999);
        PasswordResetCode::create([
            'account_type' => $accountType,
            'account_id' => $account->getKey(),
            'email' => $account->email,
            'code_hash' => Hash::make($code),
            'is_invite' => false,
            'expires_at' => now()->addMinutes(self::EXPIRES_MINUTES),
        ]);
        Mail::to($account->email)->send(new PasswordResetCodeMail($code, $accountType, $account->email, $account->name ?? $account->seller_name ?? null));
    }

    public function consume(Model $account, string $accountType, string $code): bool
    {
        $reset = $this->matchedCode($account, $accountType, $code);
        if (! $reset || $this->statusFor($reset) !== self::VALID) return false;
        $now = now();
        $reset->update(['used_at' => $now, 'fulfilled_at' => $reset->is_invite ? $now : null]);
        return true;
    }

    /** Checks a current code without consuming it, for the code-entry step. */
    public function check(Model $account, string $accountType, string $code): bool
    {
        return $this->inspect($account, $accountType, $code) === self::VALID;
    }

    /**
     * Returns a reason only when the submitted code itself matched a row.
     * Guessed codes remain indistinguishable from missing codes.
     */
    public function inspect(Model $account, string $accountType, string $code): string
    {
        $reset = $this->matchedCode($account, $accountType, $code);
        if ($reset) return $this->statusFor($reset);

        // Failed attempts apply only to the current usable code. Historical
        // rows are intentionally never touched by a guessed code.
        $current = PasswordResetCode::query()->where('account_type', $accountType)->where('account_id', $account->getKey())->where('email', $account->email)->whereNull('used_at')->whereNull('replaced_at')->where('expires_at', '>', now())->latest()->first();
        if (! $current) return self::INVALID;
        $attempts = $current->failed_attempts + 1;
        $current->update(['failed_attempts' => $attempts, 'used_at' => $attempts >= 5 ? now() : null]);
        return self::INVALID;
    }

    public function failurePayload(string $status): array
    {
        return match ($status) {
            self::PASSWORD_ALREADY_SET => ['message' => 'A password has already been created for this account.', 'code' => self::PASSWORD_ALREADY_SET, 'errors' => ['code' => ['A password has already been created for this account.']]],
            self::USED => ['message' => 'This code has already been used. Request a new code.', 'code' => self::USED, 'errors' => ['code' => ['This code has already been used. Request a new code.']]],
            self::EXPIRED => ['message' => 'This code has expired. Request a new code.', 'code' => self::EXPIRED, 'errors' => ['code' => ['This code has expired. Request a new code.']]],
            default => ['message' => 'Invalid or expired code.', 'errors' => ['code' => ['Invalid or expired code.']]],
        };
    }

    private function matchedCode(Model $account, string $accountType, string $code): ?PasswordResetCode
    {
        return PasswordResetCode::query()->where('account_type', $accountType)->where('account_id', $account->getKey())->where('email', $account->email)->latest()->get()->first(fn (PasswordResetCode $reset) => Hash::check($code, $reset->code_hash));
    }

    private function statusFor(PasswordResetCode $reset): string
    {
        if ($reset->replaced_at !== null) return self::USED;
        if ($reset->used_at !== null) return $reset->is_invite && $reset->fulfilled_at !== null ? self::PASSWORD_ALREADY_SET : self::USED;
        if ($reset->expires_at->isPast()) return self::EXPIRED;
        return self::VALID;
    }

    private function replaceActiveCodes(Model $account, string $accountType): void
    {
        PasswordResetCode::query()->where('account_type', $accountType)->where('account_id', $account->getKey())->whereNull('used_at')->whereNull('replaced_at')->update(['replaced_at' => now()]);
    }
}
