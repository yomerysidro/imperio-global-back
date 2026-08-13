<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SponsorRelation extends Model
{
    protected $fillable = ['user_code', 'sponsor_code', 'source', 'state'];

    protected $casts = ['state' => 'boolean'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_code', 'uuid');
    }

    public function sponsor()
    {
        return $this->belongsTo(User::class, 'sponsor_code', 'uuid');
    }
}
