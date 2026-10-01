<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SponsorshipPoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'pack_id',
        'level1',
        'level2',
        'level3',
        'level4',
        'level5',
        'level6',
        'level7',
    ];

    public function pack()
    {
        return $this->hasOne(Pack::class , 'id' , 'pack_id');
    }

    public function percentageForLevel(int $level): float
    {
        if ($level < 1 || $level > 7) return 0.0;

        return (float) $this->{'level' . $level};
    }
}
