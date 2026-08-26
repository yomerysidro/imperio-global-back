<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManualReactivation extends Model
{
    public const ACTIVE = 'active';
    public const DEACTIVATED = 'deactivated';
    public const EXPIRED = 'expired';

    protected $fillable = [
        'user_id', 'activated_by', 'category', 'period', 'minimum_points',
        'payment_product_order_id', 'payment_order_point_ids',
        'payment_product_order_point_ids', 'payment_log_ids', 'amount', 'points', 'state',
        'payment_order_ids',
        'deactivated_at', 'deactivated_by',
    ];

    protected $casts = [
        'payment_order_point_ids' => 'array',
        'payment_product_order_point_ids' => 'array',
        'payment_log_ids' => 'array',
        'payment_order_ids' => 'array',
        'amount' => 'float',
        'points' => 'float',
        'minimum_points' => 'float',
        'period' => 'date',
        'deactivated_at' => 'datetime',
    ];

    public function user() { return $this->belongsTo(User::class); }
}
