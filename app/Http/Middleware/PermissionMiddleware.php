<?php
namespace App\Http\Middleware;
use Closure; use Illuminate\Http\Request; use Symfony\Component\HttpFoundation\Response;
class PermissionMiddleware { public function handle(Request $request, Closure $next, string ...$permissions):Response { $actor=$request->attributes->get('seller_staff') ?? $request->user(); foreach($permissions as $permission) if($actor?->hasPermission($permission)) return $next($request); return response()->json(['message'=>"You don't have access to this.",'permission'=>$permissions[0]??null],403); } }
