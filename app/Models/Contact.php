<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'contact_user_id',
        'saved_name',
        'phone_number',
        'is_registered',
        'is_blocked',
        'is_favorite',
    ];

    protected $casts = [
        'is_registered' => 'boolean',
        'is_blocked'    => 'boolean',
        'is_favorite'   => 'boolean',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function contactUser()
    {
        return $this->belongsTo(User::class, 'contact_user_id');
    }
}