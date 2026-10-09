<?php

namespace App\Models;

use App\Models\Update;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_id',
        'sender_id',
        'body',
        'type',
        'attachment_path',
        'reply_to_id',
        'delivered_at',
        'read_at',
        'is_deleted',
        'deleted_at',
        'update_preview',
        'sticker_id',
        is_edited,
    ];

    protected $casts = [
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'deleted_at' => 'datetime',
        'is_deleted' => 'boolean',
        'update_preview' => 'array',
    ];

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replyTo()
    {
        return $this->belongsTo(Message::class, 'reply_to_id');
    }

    public function replies()
    {
        return $this->hasMany(Message::class, 'reply_to_id');
    }

    public function deletions()
    {
        return $this->hasMany(MessageDeletion::class);
    }

    public function sticker()
{
    return $this->belongsTo(\App\Models\Sticker::class);
}
}