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

    public function issue(Model $account, string $accountType, int $minutes = 10080): string
    {
        PasswordResetCode::query()->where('account_type', $accountType)->where('account_id', $account->getKey())->whereNull('used_at')->delete();
        $code = (string) random_int(100000, 999999);
        PasswordResetCode::create(['account_type' => $accountType, 'account_id' => $account->getKey(), 'email' => $account->email, 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes($minutes)]);
        return $code;
    }

    public function send(Model $account, string $accountType): void
    {
        PasswordResetCode::query()->where('account_type', $accountType)->where('account_id', $account->getKey())->whereNull('used_at')->delete();
        $code = (string) random_int(100000, 999999);
        PasswordResetCode::create([
            'account_type' => $accountType,
            'account_id' => $account->getKey(),
            'email' => $account->email,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::EXPIRES_MINUTES),
        ]);
        Mail::to($account->email)->send(new PasswordResetCodeMail($code, $accountType));
    }

    public function consume(Model $account, string $accountType, string $code): bool
    {
        $reset = $this->match($account, $accountType, $code);
        if (!$reset) return false;
        $reset->update(['used_at' => now()]);
        return true;
    }

    /** Checks a current code without consuming it, for the code-entry step. */
    public function check(Model $account, string $accountType, string $code): bool
    {
        return $this->match($account, $accountType, $code) !== null;
    }

    private function match(Model $account, string $accountType, string $code): ?PasswordResetCode
    {
        $reset = PasswordResetCode::query()->where('account_type', $accountType)->where('account_id', $account->getKey())->where('email', $account->email)->whereNull('used_at')->where('expires_at', '>', now())->latest()->first();
        return $reset && Hash::check($code, $reset->code_hash) ? $reset : null;
    }
}
