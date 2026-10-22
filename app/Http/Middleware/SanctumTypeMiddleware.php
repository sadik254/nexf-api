<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use App\Models\SellerStaff;
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

        $staffSeller = strtolower($type) === 'seller' && $tokenable instanceof SellerStaff;
        $typeMatches = $staffSeller || $tokenableClass === $type || $tokenableBase === strtolower($type);
        if (!$typeMatches) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($ability && !$accessToken->can($ability)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($staffSeller && (str_ends_with($request->path(), '/delete') || preg_match('~^api/seller/(staff|store)(?:/|$)~', $request->path()) && !str_starts_with($request->path(), 'api/seller/staff/me'))) return response()->json(['message' => 'Staff cannot manage store settings, staff, or delete records.'], 403);
        if ($staffSeller) { $seller = $tokenable->seller; if (!$seller) return response()->json(['message' => 'Forbidden.'], 403); $request->attributes->set('seller_staff', $tokenable); $request->setUserResolver(fn () => $seller); Auth::setUser($seller); } else { $request->setUserResolver(fn () => $tokenable); Auth::setUser($tokenable); }

        return $next($request);
    }
}
