<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FraudGuardBlock extends Model { protected $fillable=['kind','value','reason','created_by_admin_id']; }
