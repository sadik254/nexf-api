<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class HomepageOfferSet extends Model { protected $fillable=['row_type','name']; public function blocks(): HasMany { return $this->hasMany(HomepageOfferBlock::class,'offer_set_id'); } }
