<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RangeUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'user_id',
        'range_id',
        'status'
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    public function range()
    {
        return $this->hasOne(Range::class , 'id' , 'range_id');
    }
}
