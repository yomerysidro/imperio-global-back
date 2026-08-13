<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivationRule extends Model
{
    protected $fillable = ['name', 'minimum_points', 'minimum_amount', 'minimum_products', 'state'];
    protected $casts = ['minimum_points' => 'float', 'minimum_amount' => 'float', 'minimum_products' => 'integer', 'state' => 'boolean'];
}
