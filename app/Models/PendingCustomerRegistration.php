<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Registration details retained only until the owner confirms their email. */
class PendingCustomerRegistration extends Model
{
    protected $fillable = ['email', 'name', 'phone', 'password', 'profile_picture', 'referral_code'];

    protected $hidden = ['password'];
}
