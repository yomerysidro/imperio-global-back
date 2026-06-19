<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Option extends Model
{
    use HasFactory;

    protected $fillable = [
        'option_key',
        'option_value',
    ];


    protected $hidden = [
        'created_user_id',
        'updated_user_id'
    ];
}
