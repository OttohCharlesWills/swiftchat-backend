<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

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
        'is_edited',
        'is_deleted',
        'delivered_at',
        'read_at',
    ];

    protected $casts = [
        'is_edited'    => 'boolean',
        'is_deleted'   => 'boolean',
        'delivered_at' => 'datetime',
        'read_at'      => 'datetime',
    ];

    // Encrypt automatically whenever body is set
    public function setBodyAttribute($value)
    {
        $this->attributes['body'] = $value !== null ? Crypt::encryptString($value) : null;
    }

    // Decrypt automatically whenever body is read
    public function getBodyAttribute($value)
    {
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null; // corrupted/undecryptable, fail safe rather than crash
        }
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }

    public function replyTo()
    {
        return $this->belongsTo(Message::class, 'reply_to_id');
    }
}