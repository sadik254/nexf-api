<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FraudGuardBlock extends Model
{
    protected $fillable = [
        'seller_id', 'kind', 'value', 'reason', 'created_by_admin_id',
        'created_by_type', 'created_by_id',
    ];
}
