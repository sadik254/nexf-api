<?php
namespace App\Models\Concerns;
use App\Models\RolePermission;
use Illuminate\Support\Facades\Cache;
trait HasPermissions {
 abstract public function rbacRole(): string;
 public function permissions():array { $role=$this->rbacRole(); if($role==='super_admin') return array_keys(config('rbac.permissions')); return Cache::remember("rbac.$role", 3600, fn()=>RolePermission::query()->where('role',$role)->value('permissions') ?? config("rbac.defaults.$role",[])); }
 public function hasPermission(string $permission):bool{return in_array($permission,$this->permissions(),true);}
}
