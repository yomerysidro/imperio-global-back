<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Option extends Model
{
    use HasFactory;

    public const ASSIGNED_PACK_PREFIX = 'member.assigned-pack.';

    public function pack()
    {
        return $this->belongsTo(Pack::class, 'option_value');
    }

    protected $fillable = [
        'option_key',
        'option_value',
    ];


    protected $hidden = [
        'created_user_id',
        'updated_user_id'
    ];
}
