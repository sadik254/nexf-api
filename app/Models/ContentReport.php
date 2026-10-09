<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentReport extends Model
{
    protected $fillable = ['reporter_customer_id', 'target_type', 'target_id', 'product_id', 'reason', 'note', 'status', 'resolution', 'resolved_by_admin_id', 'resolved_at'];
    protected function casts(): array { return ['resolved_at' => 'datetime']; }
    public function reporter(): BelongsTo { return $this->belongsTo(Customer::class, 'reporter_customer_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function resolver(): BelongsTo { return $this->belongsTo(Admin::class, 'resolved_by_admin_id'); }
}
