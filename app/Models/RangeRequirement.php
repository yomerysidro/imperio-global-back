<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RangeRequirement extends Model
{
    protected $fillable = ['range_id', 'required_range_id', 'required_count', 'minimum_distinct_lines'];
    protected $casts = ['required_count' => 'integer', 'minimum_distinct_lines' => 'integer'];
    public function requiredRange() { return $this->belongsTo(Range::class, 'required_range_id'); }
}
