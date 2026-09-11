<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DesktopSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token',
        'code',
        'duration',
        'expires_at',
        'status',
        'attempts',
        'verified_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'verified_at' => 'datetime',
    ];

    protected $hidden = [
        'code', // never expose the code in API responses except right after generation
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return now()->greaterThan($this->expires_at);
    }
}