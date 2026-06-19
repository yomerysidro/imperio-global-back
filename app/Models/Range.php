<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Range extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'points',
        'childs',
        'state',
        'file',
        'order'
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    public function file()
    {
        return $this->hasOne(File::class , 'id' , 'file');
    }
}
