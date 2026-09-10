<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CourierWebhookEvent extends Model
{
    protected $fillable = ['provider','notification_type','consignment_id','invoice','event_hash','payload','processed_at','processing_error'];
    protected function casts(): array { return ['payload' => 'array', 'processed_at' => 'datetime']; }
}
