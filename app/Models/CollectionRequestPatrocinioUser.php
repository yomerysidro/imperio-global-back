<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CollectionRequestPatrocinioUser extends Model
{
    use HasFactory;

    public const PENDING = 1;
    public const PAID = 2;
    public const REJECTED = 3;

    protected $fillable = [
        'id',
        'user_id',
        'points',
        'amount',
        'period',
        'state',
        'file',
        'confirm',
        'approved_by',
        'requested_at',
        'approved_at',
        'paid_at',
        'rejection_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'period' => 'date',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'confirm' => 'datetime',
    ];

    public function user()
    {
        return $this->hasOne(User::class , "id", "user_id");
    }

    public function fileModel()
    {
        return $this->hasOne(File::class , "id", "file");
    }
}
