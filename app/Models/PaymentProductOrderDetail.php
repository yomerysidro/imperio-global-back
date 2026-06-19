<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentProductOrderDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_product_order_id',
        'product_id',
        'product_title',
        'quantity',
        'price',
        'subtotal',
        'points'
    ];

    public function paymentProductOrder()
    {
        return $this->hasOne(PaymentProductOrder::class, 'id', 'payment_product_order_id');
    }
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
