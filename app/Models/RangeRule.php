<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RangeRule extends Model
{
    protected $fillable = ['range_id', 'required_points', 'required_active_lines', 'depth_from', 'depth_to', 'infinity_percentage', 'state'];
    protected $casts = ['required_points' => 'float', 'required_active_lines' => 'integer', 'depth_from' => 'integer', 'depth_to' => 'integer', 'infinity_percentage' => 'float', 'state' => 'boolean'];

    public function range() { return $this->belongsTo(Range::class); }
    public function requirements() { return $this->hasMany(RangeRequirement::class, 'range_id', 'range_id'); }
}
