<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LogPayment extends Model
{
    use HasFactory;

    const IZIPAY = "IZIPAY";
    const FLOW = "FLOW";

    const OTHER = "OTHER";

    const FLOWPRODUCT = "FLOWPRODUCT";
    const IZIPAYPRODUCT = "IZIPAYPRODUCT";

    const OFFLINE = "OFFLINE";


    protected $fillable = [
        'id',
        'type',
        'message',
        'apiController',
        'jsonRequest',
        'jsonResponse',
        'log_order_id'
    ];
}
