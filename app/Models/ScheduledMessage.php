<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduledMessage extends Model
{
    protected $fillable = [
        'chat_id',
        'sender_id',
        'body',
        'scheduled_at',
        'status',
        'sent_at',
        'message_id',
        'failure_reason',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}