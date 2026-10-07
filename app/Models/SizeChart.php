<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SizeChart extends Model
{
    protected $fillable = ['seller_id', 'name', 'url', 'unit', 'audience', 'category_slug', 'subcategory_slug', 'columns', 'rows', 'note'];

    protected function casts(): array
    {
        return ['columns' => 'array', 'rows' => 'array'];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
