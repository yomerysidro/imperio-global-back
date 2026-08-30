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
        'is_promotion',
        'promotion_start_at',
        'promotion_end_at',
        'reactivation_category',
    ];

    protected $hidden = [
        'user_id'
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'points' => 'integer',
            'stock' => 'integer',
            'state' => 'boolean',
            'is_promotion' => 'boolean',
            'promotion_start_at' => 'datetime',
            'promotion_end_at' => 'datetime',
        ];
    }

    public function file_image()
    {
        return $this->hasOne(File::class , 'id' , 'file');
    }

}
