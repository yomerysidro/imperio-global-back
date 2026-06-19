<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GuestsTokenUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'sponsor_user_code',
        'guest_user_code',
        'invite_user_id',
        'state'
    ];

    
}
