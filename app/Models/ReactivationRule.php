<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReactivationRule extends Model
{
    public const PRODUCT = 'product';
    public const SERVICE = 'service';

    protected $fillable = [
        'name', 'category', 'minimum_points', 'minimum_amount', 'minimum_products', 'state',
    ];

    protected $casts = [
        'minimum_points' => 'float',
        'minimum_amount' => 'float',
        'minimum_products' => 'integer',
        'state' => 'boolean',
    ];
}
