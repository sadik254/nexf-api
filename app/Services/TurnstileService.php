<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Cloudflare Turnstile - the "are you human?" check on checkout. Does nothing
 * until TURNSTILE_SECRET_KEY is set; then every order needs a valid token.
 */
class TurnstileService
{
    public function assertHuman(Request $request, bool $required = false): void
    {
        $secret = config('services.turnstile.secret_key');
        if (!$secret) {
            if ($required) throw ValidationException::withMessages(['turnstile_token' => ['Guest checkout is temporarily unavailable until the security check is configured.']]);
            return;
        }

        $token = (string) $request->input('turnstile_token', '');
        if ($token === '') {
            throw ValidationException::withMessages([
                'turnstile_token' => ['Please complete the security check.'],
            ]);
        }

        try {
            $result = Http::asForm()->timeout(8)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $request->ip(),
            ])->json();
        } catch (\Throwable $e) {
            Log::warning('Turnstile verification unavailable', ['error' => $e->getMessage()]);
            throw ValidationException::withMessages(['turnstile_token' => ['The security check is temporarily unavailable. Please try again.']]);
        }

        if (!($result['success'] ?? false)) {
            throw ValidationException::withMessages([
                'turnstile_token' => ['The security check failed. Please try again.'],
            ]);
        }
    }
}
