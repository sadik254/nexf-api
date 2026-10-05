<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreChatMessage extends Model
{
    protected $fillable = ['store_chat_id', 'author_type', 'author_id', 'body', 'attachments'];
    protected function casts(): array { return ['attachments' => 'array']; }
    public function chat(): BelongsTo { return $this->belongsTo(StoreChat::class, 'store_chat_id'); }
}
