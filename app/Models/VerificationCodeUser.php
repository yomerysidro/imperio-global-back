<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Uuid;

class VerificationCodeUser extends Model
{

    use HasFactory, Uuid;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $primaryKey = 'id';

    protected $fillable = [
        'user_id',
        'code',
        'type',
        'state',
    ];

    protected $hidden = [
        'created_user_id',
        'updated_user_id'
    ];
}
