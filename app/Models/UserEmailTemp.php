<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserEmailTemp extends Model
{
    use HasFactory;

    const PENDIENTE = "PENDIENTE";
    const ENVIADO = "ENVIADO";
    const ERROR = "ERROR";

    protected $fillable = [
        'id',
        'userId',
        'isAdmin',
        'status',
        'email',
        'subject',
        'month',
        'year',
        'jsonBody',
        'jsonError',
        'fileAttachment',
    ];
}
