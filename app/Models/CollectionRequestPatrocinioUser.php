<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CollectionRequestPatrocinioUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'user_id',
        'points',
        'state',
        'file',
        'confirm'
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
