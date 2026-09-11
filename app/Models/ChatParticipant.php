<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatParticipant extends Model
{
    use HasFactory;

    protected $fillable = ['chat_id', 'user_id', 'role', 'joined_at', 'last_read_at', 'is_muted'];

    protected $casts = [
        'joined_at'    => 'datetime',
        'last_read_at' => 'datetime',
        'is_muted'     => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }
}