<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentProductOrderPoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_product_order_id',
        'user_id',
        'points',
        'state'
    ];

    protected $hidden = [
        'user_id'
    ];
}
