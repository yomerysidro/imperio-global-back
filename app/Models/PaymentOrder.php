<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Uuid;

class PaymentOrder extends Model
{
    use HasFactory, Uuid;

    public $incrementing = false;
    protected $keyType = 'string';
    protected $primaryKey = 'id';

    // ✅ ESPECIFICAR EL NOMBRE EXACTO DE LA TABLA (sin prefijo)
    protected $table = 'payment_orders';

    protected $fillable = ['id', 'currency', 'amount', 'sponsor_code', 'pack_id', 'token', 'user_id'];

    // ✅ RELACIÓN CON PAYMENTLOG (CORREGIDO - nombre correcto)
    public function paymentLog()
    {
        return $this->hasOne(PaymentLog::class, 'payment_order_id', 'id');
    }

    // ✅ Alias por si usas payment_log (con guión bajo)
    public function payment_log()
    {
        return $this->hasOne(PaymentLog::class, 'payment_order_id', 'id');
    }

    public function pack()
    {
        return $this->belongsTo(Pack::class, 'pack_id', 'id');
    }

    public function sponsor()
    {
        return $this->belongsTo(User::class, 'sponsor_code', 'uuid');
    }
}