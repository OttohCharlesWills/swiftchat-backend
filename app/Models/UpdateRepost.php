<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UpdateRepost extends Model
{
    use HasFactory;

    protected $fillable = [
        'update_id',
        'user_id',
    ];

    public function update()
    {
        return $this->belongsTo(Update::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

