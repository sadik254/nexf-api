<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AuditLog extends Model { public $timestamps=false; protected $fillable=['actor_type','actor_id','staff_id','permission','action','subject_type','subject_id','changes','ip','created_at']; protected function casts():array{return ['changes'=>'array','created_at'=>'datetime'];} }
