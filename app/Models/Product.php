<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Uuid;

class Product extends Model
{
    use HasFactory, Uuid;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $primaryKey = 'id';

    protected $fillable = [
        'id',
        'title',
        'price',
        'points',
        'stock',
        'file',
        'user_id',
        'state',
    ];

    protected $hidden = [
        'user_id'
    ];

    public function file_image()
    {
        return $this->hasOne(File::class , 'id' , 'file');
    }

}
