<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsoleNotification extends Model
{
    protected $fillable = ['recipient_type', 'recipient_id', 'type', 'title', 'body', 'data', 'source_key', 'read_at'];
    protected function casts(): array { return ['data' => 'array', 'read_at' => 'datetime']; }
}
