<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewMessage implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public Message $message;

    public function __construct(Message $message)
    {
        $this->message = $message;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.' . $this->message->chat_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'chat_id' => $this->message->chat_id,
            'sender_id' => $this->message->sender_id,
            'body' => $this->message->body,
            'type' => $this->message->type,
            'attachment_path' => $this->message->attachment_path,
            'reply_to_id' => $this->message->reply_to_id,
            'is_edited' => $this->message->is_edited,
            'is_deleted' => $this->message->is_deleted,
            'delivered_at' => $this->message->delivered_at,
            'read_at' => $this->message->read_at,
            'created_at' => $this->message->created_at,
            'sender' => $this->message->sender,
        ];
    }
}