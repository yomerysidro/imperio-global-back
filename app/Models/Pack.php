<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Traits\Uuid;

class Pack extends Model
{
    use HasFactory, Uuid;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $primaryKey = 'id';

    protected $fillable = [
        'id',
        'title',
        'category',
        'price',
        'points',
        'state',
        'image',
        'discount'
    ];

    protected $hidden = [
        'created_user_id',
        'updated_user_id'
    ];

    public function file()
    {
        return $this->hasOne(File::class , 'id' , 'image');
    }
}
