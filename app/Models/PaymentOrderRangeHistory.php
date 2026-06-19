<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentOrderRangeHistory extends Model
{
    use HasFactory;

    protected $fillable = [

        'payment_order_id',
        'pack_id',
        'points',
        'user_id',
        'cron'
    ];
}
