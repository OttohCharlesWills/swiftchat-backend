<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

protected $fillable = [
    'phone_number',
    'name',
    'username',
    'bio',
    'avatar_url',
    'profile_link',
    'password',
    'theme',
    'font_id',
    'language',
    'last_seen_visibility',
    'profile_photo_visibility',
    'read_receipts_enabled',
    'otp_code',
    'otp_expires_at',
];

    protected $hidden = [
        'password',
        'otp_code',
        'remember_token',
    ];

    protected $casts = [
        'otp_expires_at'     => 'datetime',
        'last_seen_at'       => 'datetime',
        'is_verified'        => 'boolean',
        'is_online'          => 'boolean',
        'read_receipts_enabled' => 'boolean',
    ];

    protected function phoneNumber(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            set: fn (string $value) => '+' . preg_replace('/[^0-9]/', '', $value),
        );
    }

    public function font()
    {
        return $this->belongsTo(Font::class);
    }

    public function theme()
    {
        return $this->belongsTo(Theme::class);
    }
}