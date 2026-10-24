<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $key = fn (Request $request) => $request->ip().'|'.mb_strtolower(trim((string) $request->input('email')));
        RateLimiter::for('auth-login', fn (Request $request) => Limit::perMinute(5)->by($key($request)));
        RateLimiter::for('auth-forgot', fn (Request $request) => Limit::perMinutes(15, 3)->by($key($request)));
        RateLimiter::for('auth-reset', fn (Request $request) => Limit::perMinute(10)->by($key($request)));
        RateLimiter::for('auth-verify', fn (Request $request) => Limit::perMinutes(15, 5)->by($key($request)));
        RateLimiter::for('auth-resend', fn (Request $request) => Limit::perMinutes(15, 3)->by($key($request)));
    }
}
