<?php

namespace App\Services;

use App\Events\NewMessage;
use App\Http\Controllers\Api\MessageController;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Message;
use App\Models\ScheduledMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Turns a scheduled message into a real message (same way a normal one is
 * sent: saved, broadcast live, push notification).
 *
 * NOTE: MessageController::sendPushToOtherParticipants() must be PUBLIC.
 * (GroupController already needs the same thing for invite links.)
 */
class ScheduledMessageSender
{
    public static function send(ScheduledMessage $scheduled): bool
    {
        // Claim it first, so two runs at the same time can never send twice.
        $claimed = ScheduledMessage::where('id', $scheduled->id)
            ->where('status', 'pending')
            ->update(['status' => 'sending']);

        if (!$claimed) {
            return false;
        }

        try {
            $chat = Chat::find($scheduled->chat_id);
            $sender = User::find($scheduled->sender_id);

            if (!$chat || !$sender) {
                return self::fail($scheduled, 'chat_or_user_missing');
            }

            $isMember = $chat->participants()
                ->where('user_id', $sender->id)
                ->exists();

            if (!$isMember) {
                return self::fail($scheduled, 'not_in_chat_anymore');
            }

            // Private chat: the other person may have blocked the sender.
            if (($chat->type ?? '') === 'private') {
                $other = $chat->participants()
                    ->where('user_id', '!=', $sender->id)
                    ->value('user_id');

                if ($other && Contact::where('user_id', $other)
                    ->where('contact_user_id', $sender->id)
                    ->where('is_blocked', true)
                    ->exists()) {
                    return self::fail($scheduled, 'blocked');
                }
            }

            $message = Message::create([
                'chat_id' => $chat->id,
                'sender_id' => $sender->id,
                'body' => $scheduled->body,
                'type' => 'text',
                'is_deleted' => false,
                'deleted_for_user_ids' => null,
            ]);

            $message->load(['sender', 'replyTo.sender', 'sticker']);

            try {
                broadcast(new NewMessage($message));
            } catch (\Throwable $e) {
                Log::warning('Scheduled message broadcast failed', [
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                // Same push code as a normal message. There is no web
                // request here, so give it one that says who the sender is.
                $request = Request::create('/');
                $request->setUserResolver(fn () => $sender);

                app(MessageController::class)
                    ->sendPushToOtherParticipants($request, $chat, $message);
            } catch (\Throwable $e) {
                Log::warning('Scheduled message push failed', [
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $scheduled->forceFill([
                'status' => 'sent',
                'sent_at' => now(),
                'message_id' => $message->id,
            ])->save();

            return true;
        } catch (\Throwable $e) {
            Log::error('Scheduled message failed', [
                'scheduled_id' => $scheduled->id,
                'error' => $e->getMessage(),
            ]);

            return self::fail($scheduled, 'error');
        }
    }

    private static function fail(ScheduledMessage $scheduled, string $reason): bool
    {
        $scheduled->forceFill([
            'status' => 'failed',
            'failure_reason' => $reason,
        ])->save();

        return false;
    }
}