<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UpdateSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'allow_reposts',
    ];

    protected $casts = [
        'allow_reposts' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

