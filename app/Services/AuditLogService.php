<?php
namespace App\Services;
use App\Models\AuditLog;use Illuminate\Http\Request;
class AuditLogService { public function record(Request $request,string $permission,string $action,mixed $subject=null,array $changes=[]):void{$actor=$request->attributes->get('seller_staff')??$request->user();if(!$actor)return;AuditLog::create(['actor_type'=>$actor::class,'actor_id'=>$actor->id,'staff_id'=>$request->attributes->get('seller_staff')?->id,'permission'=>$permission,'action'=>$action,'subject_type'=>is_object($subject)?$subject::class:null,'subject_id'=>is_object($subject)?$subject->id:null,'changes'=>$changes?:null,'ip'=>$request->ip()]);} }
