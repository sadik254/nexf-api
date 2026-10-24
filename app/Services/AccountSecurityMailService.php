<?php

namespace App\Services;

use App\Mail\EmailChangedMail;
use App\Mail\PasswordChangedMail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AccountSecurityMailService
{
    public function emailChanged(Model $account, string $oldEmail, string $newEmail): void
    {
        $base = rtrim((string) config('app.frontend_url'), '/');
        $data = ['name' => $account->name ?? $account->seller_name ?? null, 'accountType' => $this->type($account), 'oldEmail' => $oldEmail, 'newEmail' => $newEmail, 'changedAt' => now()->timezone('Asia/Dhaka')->format('j M Y, g:i A'), 'supportUrl' => $base ? $base.'/contact' : null, 'signInUrl' => $base ? $base.'/login' : null];
        $this->send($oldEmail, new EmailChangedMail($data + ['recipient' => 'old']));
        $this->send($newEmail, new EmailChangedMail($data + ['recipient' => 'new']));
    }

    public function passwordChanged(Model $account, string $via, ?string $ip = null): void
    {
        $base = rtrim((string) config('app.frontend_url'), '/');
        $this->send($account->email, new PasswordChangedMail(['name' => $account->name ?? $account->seller_name ?? null, 'accountType' => $this->type($account), 'via' => $via, 'changedAt' => now()->timezone('Asia/Dhaka')->format('j M Y, g:i A'), 'ip' => $ip, 'recoverUrl' => $base ? $base.'/forgot-password' : null]));
    }

    private function send(string $email, object $mail): void
    {
        try { Mail::to($email)->send($mail); } catch (\Throwable $exception) { Log::warning('Account security email failed', ['email' => $email, 'error' => $exception->getMessage()]); }
    }

    private function type(Model $account): string
    {
        return match (class_basename($account)) { 'SellerStaff' => 'staff', 'Seller' => 'seller', 'Reseller' => 'reseller', 'Admin' => 'admin', default => 'customer' };
    }
}
