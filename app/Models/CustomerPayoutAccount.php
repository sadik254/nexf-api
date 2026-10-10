<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CustomerPayoutAccount extends Model {protected $fillable=['customer_id','provider','account','holder','bank','branch','is_selected'];protected function casts(): array{return ['is_selected'=>'boolean'];}}
