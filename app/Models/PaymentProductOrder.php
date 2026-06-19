<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Uuid;

class PaymentProductOrder extends Model
{
    const PENDIENTEPAGO = 1;
    const PAGADO = 2;
    const ENVIADO = 3;
    const ANULADO = 4;
    const ERROR = 5;
    const TERMINADO = 6;
    const PREORDER = 9;

    use HasFactory, Uuid;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $primaryKey = 'id';

    protected $fillable = [
        'id',
        'currency',
        'amount',
        'discount',
        'points',
        'user_id',
        'pack_id',
        'phone',
        'address',
        'state',
        'type',
        'token',
        'file'
    ];

    public function user()
    {
        return $this->hasOne(User::class, 'id' ,'user_id');
    }

    public function pack()
    {
        return $this->hasOne(Pack::class, 'id' ,'pack_id');
    }

    public function details()
    {
        return $this->hasMany(PaymentProductOrderDetail::class, 'payment_product_order_id' ,'id');
    }

    protected $hidden = [
        'user_id',
    ];

    public function fileImage()
    {
        return $this->hasOne(File::class , 'id' , 'file');
    }
}
