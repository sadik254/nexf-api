<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class DeliveryGroup extends Model {
 protected $fillable=['name','seller_ids','includes_house','ships_from_seller_id'];
 protected function casts():array{return ['seller_ids'=>'array','includes_house'=>'boolean'];}
 public function shipsFromSeller():BelongsTo{return $this->belongsTo(Seller::class,'ships_from_seller_id');}
}
