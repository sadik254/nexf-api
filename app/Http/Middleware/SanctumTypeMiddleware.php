<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class SanctumTypeMiddleware
{
    public function handle(Request $request, Closure $next, string $type, ?string $ability = null): Response
    {
        $token = $request->bearerToken();
        if (!$token) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken || !$accessToken->tokenable) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $tokenable = $accessToken->tokenable;
        $type = ltrim($type, '\\');
        $tokenableClass = ltrim($tokenable::class, '\\');
        $tokenableBase = strtolower(class_basename($tokenableClass));

        $typeMatches = $tokenableClass === $type || $tokenableBase === strtolower($type);
        if (!$typeMatches) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($ability && !$accessToken->can($ability)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->setUserResolver(fn () => $tokenable);
        Auth::setUser($tokenable);

        return $next($request);
    }
}
