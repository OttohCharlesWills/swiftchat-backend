<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageDeleted;
use App\Events\NewMessage;
use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Message;
use App\Models\MessageDeletion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;
use Kreait\Laravel\Firebase\Facades\Firebase;

class MessageController extends Controller
{
    // ============================================================
    // LIST MESSAGES
    // ============================================================

    public function index(Request $request, $chatId)
    {
        $chat = Chat::findOrFail($chatId);

        $this->authorizeParticipant($request, $chat);

        $userId = $request->user()->id;

        $messages = $chat->messages()
            ->whereDoesntHave('deletions', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->with([
                'sender',
                'replyTo.sender',
            ])
            ->reorder('created_at', 'desc')
            ->paginate(30);

        return response()->json($messages);
    }

    // ============================================================
    // SEND MESSAGE
    // ============================================================

    public function store(Request $request, $chatId)
    {
        $chat = Chat::findOrFail($chatId);

        $this->authorizeParticipant($request, $chat);

        $validator = Validator::make($request->all(), [
            'body' => 'required_without:attachment_path|string|nullable',
            'type' => 'nullable|in:text,image,video,file,audio',
            'attachment_path' => 'nullable|string',
            'reply_to_id' => 'nullable|exists:messages,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        // If replying, make sure the reply message belongs
        // to this same chat.
        if ($request->reply_to_id) {
            $replyMessage = Message::find($request->reply_to_id);

            if (!$replyMessage || $replyMessage->chat_id != $chat->id) {
                return response()->json([
                    'message' => 'Invalid reply message.',
                ], 422);
            }
        }

        $message = Message::create([
            'chat_id' => $chat->id,
            'sender_id' => $request->user()->id,
            'body' => $request->body,
            'type' => $request->type ?? 'text',
            'attachment_path' => $request->attachment_path,
            'reply_to_id' => $request->reply_to_id,
        ]);

        $message->load([
            'sender',
            'replyTo.sender',
        ]);

        broadcast(new NewMessage($message));

        $this->sendPushToOtherParticipants(
            $request,
            $chat,
            $message
        );

        return response()->json($message, 201);
    }

    // ============================================================
    // MARK READ
    // ============================================================

    public function markRead(Request $request, $chatId)
    {
        $chat = Chat::findOrFail($chatId);

        $this->authorizeParticipant($request, $chat);

        $authId = $request->user()->id;

        $chat->participants()
            ->where('user_id', $authId)
            ->update([
                'last_read_at' => now(),
            ]);

        Message::where('chat_id', $chatId)
            ->where('sender_id', '!=', $authId)
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'delivered_at' => now(),
            ]);

        return response()->json([
            'message' => 'Chat marked as read.',
        ]);
    }

    // ============================================================
    // MARK DELIVERED
    // ============================================================

    public function markDelivered(Request $request, $chatId)
    {
        $chat = Chat::findOrFail($chatId);

        $this->authorizeParticipant($request, $chat);

        $authId = $request->user()->id;

        Message::where('chat_id', $chatId)
            ->where('sender_id', '!=', $authId)
            ->whereNull('delivered_at')
            ->update([
                'delivered_at' => now(),
            ]);

        return response()->json([
            'message' => 'Messages marked delivered.',
        ]);
    }

    // ============================================================
    // DELETE FOR ME
    // ============================================================

    public function destroy(
        Request $request,
        $chatId,
        $messageId
    ) {
        $chat = Chat::findOrFail($chatId);

        $this->authorizeParticipant($request, $chat);

        $message = Message::where('chat_id', $chatId)
            ->where('id', $messageId)
            ->firstOrFail();

        MessageDeletion::firstOrCreate([
            'message_id' => $message->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Message deleted for you.',
            'delete_type' => 'for_me',
            'message_id' => $message->id,
        ]);
    }

    // ============================================================
    // DELETE FOR EVERYONE
    // ============================================================

    public function destroyForEveryone(
        Request $request,
        $chatId,
        $messageId
    ) {
        $chat = Chat::findOrFail($chatId);

        $this->authorizeParticipant($request, $chat);

        $message = Message::where('chat_id', $chatId)
            ->where('id', $messageId)
            ->firstOrFail();

        // Only the original sender can delete for everyone.
        if ($message->sender_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Only the sender can delete this message for everyone.',
            ], 403);
        }

        // 20-minute limit.
        if ($message->created_at->lt(now()->subMinutes(20))) {
            return response()->json([
                'message' => 'This message is older than 20 minutes and can only be deleted for you.',
            ], 422);
        }

        if (!$message->is_deleted) {
            $message->update([
                'is_deleted' => true,
                'deleted_at' => now(),
                'body' => null,
                'attachment_path' => null,
            ]);
        }

        $message->load([
            'sender',
            'replyTo.sender',
        ]);

        broadcast(new MessageDeleted(
            $message->id,
            $chat->id,
            true
        ));

        return response()->json([
            'message' => 'Message deleted for everyone.',
            'delete_type' => 'for_everyone',
            'message_id' => $message->id,
        ]);
    }

    // ============================================================
    // UPLOAD ATTACHMENT
    // ============================================================

    public function uploadAttachment(Request $request, $chatId)
    {
        $chat = Chat::findOrFail($chatId);

        $this->authorizeParticipant($request, $chat);

        $request->validate([
            'file' => 'required|file|max:10240',
        ]);

        $uploaded = cloudinary()
            ->uploadApi()
            ->upload(
                $request->file('file')->getRealPath(),
                [
                    'folder' => 'chats',
                ]
            );

        return response()->json([
            'url' => $uploaded['secure_url'],
        ]);
    }

    // ============================================================
    // AUTHORIZE PARTICIPANT
    // ============================================================

    private function authorizeParticipant(
        Request $request,
        Chat $chat
    ): void {
        $isParticipant = $chat
            ->participants()
            ->where('user_id', $request->user()->id)
            ->exists();

        abort_unless(
            $isParticipant,
            403,
            'You are not part of this chat.'
        );
    }

    // ============================================================
    // PUSH NOTIFICATION
    // ============================================================

    private function sendPushToOtherParticipants(
        Request $request,
        Chat $chat,
        Message $message
    ): void {
        $recipientIds = $chat
            ->participants()
            ->where('user_id', '!=', $request->user()->id)
            ->pluck('user_id');

        if ($recipientIds->isEmpty()) {
            return;
        }

        $recipients = User::whereIn('id', $recipientIds)
            ->whereNotNull('fcm_token')
            ->get();

        foreach ($recipients as $recipient) {
            try {
                $body = $message->type === 'text'
                    ? ($message->body ?? 'New message')
                    : ucfirst($message->type);

                $cloudMessage = CloudMessage::withTarget(
                    'token',
                    $recipient->fcm_token
                )
                    ->withNotification(
                        FirebaseNotification::create(
                            $request->user()->name,
                            $body
                        )
                    )
                    ->withData([
                        'type' => 'chat_message',
                        'chat_id' => (string) $chat->id,
                        'message_id' => (string) $message->id,
                        'sender_id' => (string) $request->user()->id,
                    ])
                    ->withAndroidConfig(
                        AndroidConfig::fromArray([
                            'priority' => 'high',
                            'notification' => [
                                'channel_id' => 'chat_messages',
                                'sound' => 'default',
                            ],
                        ])
                    );

                Firebase::messaging()->send($cloudMessage);
            } catch (\Throwable $e) {
                Log::warning(
                    'FCM push failed',
                    [
                        'user_id' => $recipient->id,
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }
    }
}