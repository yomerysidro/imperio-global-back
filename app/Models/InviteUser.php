<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InviteUser extends Model
{
    use HasFactory;

    const LINK = 1;
    const EMAIL = 2;

    protected $fillable = [
        'id',
        'sponsor_user_id',
        'sponsor_user_code',
        'token',
        'state',
        'type',
        'expired_time',
    ];

}
