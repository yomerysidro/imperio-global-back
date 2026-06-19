<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentOrderPoint extends Model
{
    use HasFactory;

    // ✅ ESPECIFICAR EL NOMBRE EXACTO DE LA TABLA (sin prefijo)
    protected $table = 'payment_order_points';

    const PATROCINIO = "P";
    const RESIDUAL = "R";
    const GRUPAL = "G";

    const PATROCINIO_SERVICIO = "PS";
    const RESIDUAL_SERVICIO = "RS";

    const COMPRA = "B";
    const RESET = "X";
    const INFINITO = "I";

    const AFILIADOS = "A";

    protected $fillable = [
        'payment_order_id',
        'user_code',
        'sponsor_code',
        'point',
        'payment',
        'type',
        'state',
        'user_id'
    ];

    protected $hidden = [
        'created_user_id',
        'updated_user_id'
    ];

    public function paymentOrder()
    {
        return $this->belongsTo(PaymentOrder::class, 'payment_order_id', 'id');
    }

    public function sponsor()
    {
        return $this->belongsTo(User::class, 'sponsor_code', 'uuid');
    }

    public function patrocinador()
    {
        return $this->belongsTo(User::class, 'sponsor_code', 'uuid');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_code', 'uuid');
    }

    public function userPoint()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}