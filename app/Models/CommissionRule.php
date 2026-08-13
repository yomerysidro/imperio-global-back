<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionRule extends Model
{
    public const SPONSORSHIP = 'sponsorship';
    public const RESIDUAL = 'residual';

    protected $fillable = ['bonus_type', 'pack_id', 'minimum_range_id', 'level', 'percentage', 'state'];
    protected $casts = ['level' => 'integer', 'percentage' => 'float', 'state' => 'boolean'];
    public function minimumRange() { return $this->belongsTo(Range::class, 'minimum_range_id'); }
}
