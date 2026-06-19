<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductPointPack extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'pack_id',
        'point',
    ];

    public function pack()
    {
        return $this->hasOne(Pack::class , 'id' , 'pack_id');
    }

    public function product()
    {
        return $this->hasOne(Product::class , 'id' , 'product_id');
    }
}
