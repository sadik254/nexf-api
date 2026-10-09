<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FraudGuardSetting extends Model { protected $guarded=[]; protected function casts(): array { return ['enabled'=>'boolean','ip_block'=>'boolean','device_block'=>'boolean','phone_blacklist'=>'boolean','fake_number_detection'=>'boolean']; } }
