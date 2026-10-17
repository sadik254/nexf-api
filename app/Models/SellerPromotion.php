<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerPromotion extends Model
{
    protected $fillable = ['seller_id', 'title', 'layout', 'blocks', 'pinned', 'status', 'review_note', 'reviewed_at'];
    public const LAYOUTS = ['four' => 4, 'wide' => 1, 'two' => 2];
    public const STATUSES = ['draft', 'pending', 'approved', 'rejected'];
    public const THEMES = ['slate', 'gray', 'zinc', 'neutral', 'stone', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose'];
    protected function casts(): array { return ['blocks' => 'array', 'pinned' => 'boolean', 'reviewed_at' => 'datetime']; }
    public function seller(): BelongsTo { return $this->belongsTo(Seller::class); }
}
