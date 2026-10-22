<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class SellerStaff extends Authenticatable { use HasApiTokens, Notifiable; protected $fillable=['seller_id','name','email','phone','image','password','status','last_login_at']; protected $hidden=['password','remember_token']; protected function casts():array{return ['password'=>'hashed','last_login_at'=>'datetime'];} public function seller():BelongsTo{return $this->belongsTo(Seller::class);} }
