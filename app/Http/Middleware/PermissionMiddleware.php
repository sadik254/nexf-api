<?php
namespace App\Http\Middleware;
use App\Services\AuditLogService;
use Closure;use Illuminate\Http\Request;use Symfony\Component\HttpFoundation\Response;
class PermissionMiddleware {
 private const AUDITED_PERMISSIONS=['payments.confirm','payments.delete','withdrawals.settle','staff.manage','admins.manage','stores.manage','stores.delete'];
 public function handle(Request $request,Closure $next,string ...$permissions):Response {
  $actor=$request->attributes->get('seller_staff')??$request->user();
  foreach($permissions as $permission) if($actor?->hasPermission($permission)) { $response=$next($request); $this->audit($request,$response,$permission); return $response; }
  return response()->json(['message'=>"You don't have access to this.",'permission'=>$permissions[0]??null],403);
 }
 private function audit(Request $request,Response $response,string $permission):void {
  if($response->getStatusCode()>=400 || !in_array($request->method(),['POST','PUT','PATCH','DELETE'],true)) return;
  $path=$request->path();
  // Controllers may elevate an action's audit label when a route has a
  // broader permission (for example, payment confirmation on an order edit).
  $permission=(string)$request->attributes->get('audit_permission',$permission);
  $sensitive=str_contains($path,'/delete')||in_array($permission,self::AUDITED_PERMISSIONS,true);
  if(!$sensitive || $request->attributes->get('rbac_audited'))return;
  $request->attributes->set('rbac_audited', true);
  app(AuditLogService::class)->record($request,$permission,$request->method().' '.$path,null,$request->except(['password','current_password','new_password','new_password_confirmation','image','store_logo','kyc_document']));
 }
}
