<?php
namespace App\Http\Middleware;
use App\Services\AuditLogService;
use Closure;use Illuminate\Http\Request;use Symfony\Component\HttpFoundation\Response;
class PermissionMiddleware {
 public function handle(Request $request,Closure $next,string ...$permissions):Response {
  $actor=$request->attributes->get('seller_staff')??$request->user();
  foreach($permissions as $permission) if($actor?->hasPermission($permission)) { $response=$next($request); $this->audit($request,$response,$permission); return $response; }
  return response()->json(['message'=>"You don't have access to this.",'permission'=>$permissions[0]??null],403);
 }
 private function audit(Request $request,Response $response,string $permission):void {
  if($response->getStatusCode()>=400 || !in_array($request->method(),['POST','PUT','PATCH','DELETE'],true)) return;
  $path=$request->path();
  $sensitive=str_contains($path,'/delete')||str_contains($path,'/withdrawals/')||str_contains($path,'/payment/delete')||str_contains($path,'/seller-staff')||str_contains($path,'/sellers/')&&preg_match('~/(approve|status|delete)$~',$path)||$permission==='admins.manage';
  if(!$sensitive || $request->attributes->get('rbac_audited'))return;
  $request->attributes->set('rbac_audited', true);
  app(AuditLogService::class)->record($request,$permission,$request->method().' '.$path,null,$request->except(['password','current_password','new_password','new_password_confirmation','image','store_logo','kyc_document']));
 }
}
