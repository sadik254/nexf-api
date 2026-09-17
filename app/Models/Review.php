<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Review extends Model { protected $fillable = ['product_id','customer_id','order_item_id','rating','comment','status']; protected function casts(): array { return ['rating'=>'integer']; } public function customer(): BelongsTo { return $this->belongsTo(Customer::class); } public function product(): BelongsTo { return $this->belongsTo(Product::class); } }
