<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class RolePermission extends Model { protected $fillable=['role','permissions','updated_by']; protected function casts():array{return ['permissions'=>'array'];} }
