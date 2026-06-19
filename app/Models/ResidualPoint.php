<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResidualPoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'level1',
        'level2',
        'level3',
        'level4',
        'level5',
        'level6',
        'level7',
    ];
}
