<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SponsorshipPoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'pack_id',
        'level1',
        'level2',
        'level3',
        'level4',
        'level5',
    ];

    public function pack()
    {
        return $this->hasOne(Pack::class , 'id' , 'pack_id');
    }
}
