<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Chat extends Model
{
    use HasFactory;

    protected $fillable = ['type', 'name', 'group_avatar', 'created_by'];

    public function participants()
    {
        return $this->hasMany(ChatParticipant::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'chat_participants');
    }

    public function messages()
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    private function sendPushToOtherParticipants(Request $request, Chat $chat, Message $message): void
{
    $recipients = $chat->users()
        ->where('users.id', '!=', $request->user()->id)
        ->whereNotNull('fcm_token')
        ->get();

    foreach ($recipients as $recipient) {
        try {
            $cloudMessage = CloudMessage::withTarget('token', $recipient->fcm_token)
                ->withNotification(FirebaseNotification::create(
                    $request->user()->name,
                    $message->type === 'text' ? $message->body : ucfirst($message->type)
                ))
                ->withData(['chat_id' => (string) $chat->id]);

            Firebase::messaging()->send($cloudMessage);
        } catch (\Throwable $e) {
            \Log::warning('FCM push failed', ['user_id' => $recipient->id, 'error' => $e->getMessage()]);
        }
    }
}
}