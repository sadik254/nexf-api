<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CustomerAddress extends Model {
 protected $fillable=['customer_id','label','recipient','phone','line','area','district','is_default'];
 protected function casts(): array {return ['is_default'=>'boolean'];}
}
