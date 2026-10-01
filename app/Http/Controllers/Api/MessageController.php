<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageDeleted;
use App\Events\NewMessage;
use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Message;
use App\Models\User;
use App\Services\SupabaseService;
use App\Services\UnreadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;
use Kreait\Laravel\Firebase\Facades\Firebase;

class MessageController extends Controller
{
    /**
     * true  = one notification per chat that updates in place
     *         (a new message from the same chat replaces the previous one)
     * false = every message gets its own notification
     */
    private const GROUP_BY_CHAT = true;

    public function __construct(
        protected SupabaseService $supabase
    ) {
    }

    // ============================================================
    // LIST MESSAGES
    // ============================================================

    public function index(Request $request, $chatId)
    {
        $chat = Chat::findOrFail($chatId);

        $this->authorizeParticipant($request, $chat);

        $userId = (int) $request->user()->id;

        $messages = $chat->messages()
            ->where(function ($query) use ($userId) {
                $query->whereNull('deleted_for_user_ids')
                    ->orWhereJsonDoesntContain(
                        'deleted_for_user_ids',
                        $userId
                    );
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
            'duration_seconds' => 'nullable|integer|min:0|max:3600',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        // --------------------------------------------------------
        // VALIDATE REPLY
        // --------------------------------------------------------

        if ($request->reply_to_id) {
            $replyMessage = Message::find($request->reply_to_id);

            if (
                !$replyMessage ||
                (int) $replyMessage->chat_id !== (int) $chat->id
            ) {
                return response()->json([
                    'message' => 'Invalid reply message.',
                ], 422);
            }

            // Don't allow replying to a message that the current
            // user has deleted for themselves.
            $deletedFor = $replyMessage->deleted_for_user_ids ?? [];

            if (
                in_array(
                    (int) $request->user()->id,
                    array_map('intval', $deletedFor),
                    true
                )
            ) {
                return response()->json([
                    'message' => 'You cannot reply to this message.',
                ], 422);
            }
        }

        // --------------------------------------------------------
        // CREATE MESSAGE
        // --------------------------------------------------------

        $type = $request->type ?? 'text';

        $message = Message::create([
            'chat_id' => $chat->id,
            'sender_id' => $request->user()->id,
            'body' => $request->body,
            'type' => $type,
            'attachment_path' => $request->attachment_path,
            'reply_to_id' => $request->reply_to_id,
            'duration_seconds' => $type === 'audio'
                ? $request->duration_seconds
                : null,
            'is_deleted' => false,
            'deleted_for_user_ids' => null,
        ]);

        // Load relationships before broadcasting so the Flutter app
        // receives the sender/reply information immediately.
        $message->load([
            'sender',
            'replyTo.sender',
        ]);

        // --------------------------------------------------------
        // PUSHER REALTIME BROADCAST
        // --------------------------------------------------------
        //
        // NewMessage should implement ShouldBroadcastNow so this
        // happens immediately without requiring a queue worker.
        //

        try {
            broadcast(new NewMessage($message));
        } catch (\Throwable $e) {
            Log::warning('Pusher message broadcast failed', [
                'message_id' => $message->id,
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);
        }

        // --------------------------------------------------------
        // FCM PUSH NOTIFICATION
        // --------------------------------------------------------

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

        $userId = (int) $request->user()->id;

        $deletedFor = $message->deleted_for_user_ids ?? [];

        // Make sure all IDs are integers.
        $deletedFor = array_map('intval', $deletedFor);

        // Don't add the same user twice.
        if (!in_array($userId, $deletedFor, true)) {
            $deletedFor[] = $userId;
        }

        $message->update([
            'deleted_for_user_ids' => array_values($deletedFor),
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

        // --------------------------------------------------------
        // ONLY ORIGINAL SENDER CAN DELETE FOR EVERYONE
        // --------------------------------------------------------

        if (
            (int) $message->sender_id !==
            (int) $request->user()->id
        ) {
            return response()->json([
                'message' =>
                    'Only the sender can delete this message for everyone.',
            ], 403);
        }

        // --------------------------------------------------------
        // 20-MINUTE LIMIT
        // --------------------------------------------------------

        if ($message->created_at->lt(now()->subMinutes(20))) {
            return response()->json([
                'message' =>
                    'This message is older than 20 minutes and can only be deleted for you.',
            ], 422);
        }

        // --------------------------------------------------------
        // ALREADY DELETED
        // --------------------------------------------------------

        if ($message->is_deleted) {
            return response()->json([
                'message' => 'Message is already deleted for everyone.',
                'delete_type' => 'for_everyone',
                'message_id' => $message->id,
            ]);
        }

        // --------------------------------------------------------
        // REMEMBER VOICE FILE
        // --------------------------------------------------------

        $voiceUrl = $message->type === 'audio'
            ? $message->attachment_path
            : null;

        // --------------------------------------------------------
        // DELETE MESSAGE CONTENT
        // --------------------------------------------------------
        //
        // IMPORTANT:
        // Your messages table does NOT contain deleted_at.
        // Therefore we only use is_deleted here.
        //

        $message->update([
            'is_deleted' => true,
            'body' => null,
            'attachment_path' => null,
        ]);

        // --------------------------------------------------------
        // DELETE VOICE FILE FROM SUPABASE
        // --------------------------------------------------------

        if ($voiceUrl) {
            try {
                $this->supabase->deleteByUrl($voiceUrl);
            } catch (\Throwable $e) {
                Log::warning('Supabase voice delete failed', [
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // --------------------------------------------------------
        // BROADCAST DELETE TO OTHER USERS
        // --------------------------------------------------------

        try {
            broadcast(new MessageDeleted(
                $message->id,
                $chat->id,
                true
            ));
        } catch (\Throwable $e) {
            Log::warning('Message deletion broadcast failed', [
                'message_id' => $message->id,
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);
        }

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
    // UPLOAD VOICE NOTE
    // ============================================================

    public function uploadVoice(Request $request, $chatId)
    {
        $chat = Chat::findOrFail($chatId);

        $this->authorizeParticipant($request, $chat);

        $request->validate([
            'file' => 'required|file|max:10240',
        ]);

        $file = $request->file('file');

        $allowed = [
            'm4a',
            'aac',
            'mp3',
            'ogg',
            'opus',
            'wav',
            'webm',
            'mp4',
            '3gp',
        ];

        $ext = strtolower(
            $file->getClientOriginalExtension()
        );

        if (!in_array($ext, $allowed, true)) {
            return response()->json([
                'message' => 'Unsupported audio format.',
            ], 422);
        }

        try {
            $uploaded = $this->supabase->uploadVoice(
                $file,
                'chats/' . $chat->id
            );
        } catch (\Throwable $e) {
            Log::error('Voice note upload failed', [
                'chat_id' => $chat->id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Could not upload voice note.',
            ], 500);
        }

        return response()->json([
            'url' => $uploaded['url'],
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
        $sender = $request->user();

        $recipientIds = $chat
            ->participants()
            ->where('user_id', '!=', $sender->id)
            ->pluck('user_id');

        if ($recipientIds->isEmpty()) {
            return;
        }

        $recipients = User::whereIn('id', $recipientIds)
            ->whereNotNull('fcm_token')
            ->get();

        // A user with no saved device token can never be notified —
        // leave a trace so "why no notification?" is easy to answer.
        foreach ($recipientIds->diff($recipients->pluck('id')) as $missingId) {
            Log::info('Push skipped: recipient has no fcm_token', [
                'user_id' => $missingId,
                'chat_id' => $chat->id,
            ]);
        }

        $isGroup = ($chat->type ?? 'private') === 'group';

        $preview = $this->previewFor($message);

        foreach ($recipients as $recipient) {
            try {
                [$title, $body, $senderName] = $this->notificationText(
                    $chat,
                    $isGroup,
                    $sender,
                    $recipient,
                    $preview
                );

                // Total unread for this person (number on the app icon).
                // If the count can't be worked out, the notification is
                // still sent — just without a number.
                $unread = $this->unreadFor($recipient->id);

                $data = [
                    'type' => 'chat_message',
                    'chat_id' => (string) $chat->id,
                    'chat_type' => $isGroup ? 'group' : 'private',
                    'chat_name' => $isGroup
                        ? (string) ($chat->name ?? '')
                        : (string) $senderName,
                    'message_id' => (string) $message->id,
                    'message_type' => (string) $message->type,
                    'sender_id' => (string) $sender->id,
                    'other_user_id' => (string) $sender->id,
                    'sender_name' => (string) $senderName,
                    'sender_avatar' => (string) ($sender->avatar_url ?? ''),
                ];

                if ($unread !== null) {
                    $data['badge'] = (string) $unread;
                }

                $androidNotification = [
                    'channel_id' => 'chat_messages',
                    'sound' => 'default',
                ];

                if (self::GROUP_BY_CHAT) {
                    $androidNotification['tag'] = 'chat_' . $chat->id;
                }

                if ($unread !== null && $unread > 0) {
                    // Number shown on the app icon by launchers that
                    // support it.
                    $androidNotification['notification_count'] = $unread;
                }

                $cloudMessage = CloudMessage::withTarget(
                    'token',
                    $recipient->fcm_token
                )
                    ->withNotification(
                        FirebaseNotification::create($title, $body)
                    )
                    ->withData($data)
                    ->withAndroidConfig(
                        AndroidConfig::fromArray([
                            'priority' => 'high',
                            'notification' => $androidNotification,
                        ])
                    );

                if ($unread !== null) {
                    $cloudMessage = $cloudMessage->withApnsConfig(
                        ApnsConfig::fromArray([
                            'payload' => [
                                'aps' => [
                                    'badge' => $unread,
                                    'sound' => 'default',
                                ],
                            ],
                        ])
                    );
                }

                Firebase::messaging()->send($cloudMessage);

                // Google accepted the push for delivery to the phone, so
                // the sender can now see the two grey ticks.
                Message::where('id', $message->id)
                    ->whereNull('delivered_at')
                    ->update(['delivered_at' => now()]);
            } catch (NotFound $e) {
                // The phone's token is dead (app uninstalled/reinstalled).
                // Clear it; the app registers a new one next time it opens.
                $recipient->update(['fcm_token' => null]);

                Log::info('FCM token no longer valid, cleared', [
                    'user_id' => $recipient->id,
                ]);
            } catch (\Throwable $e) {
                Log::warning('FCM push failed', [
                    'user_id' => $recipient->id,
                    'error_class' => get_class($e),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // ============================================================
    // NOTIFICATION DISPLAY — change how notifications look HERE
    // ============================================================

    /**
     * The short text shown for a message (the body line).
     */
    private function previewFor(Message $message): string
    {
        return match ($message->type) {
            'text'  => $message->body ?? 'New message',
            'audio' => 'Voice message',
            'image' => 'Photo',
            'video' => 'Video',
            'file'  => 'Document',
            default => ucfirst((string) $message->type),
        };
    }

    /**
     * Builds the notification title and body for one recipient.
     *
     * Private chat:  title = name  — body = message
     * Group chat:    title = group — body = "name: message"
     *
     * "name" is what the RECIPIENT saved this person as in their
     * contacts, falling back to the person's own profile name.
     *
     * @return array{0: string, 1: string, 2: string} [title, body, senderName]
     */
    private function notificationText(
        Chat $chat,
        bool $isGroup,
        User $sender,
        User $recipient,
        string $preview
    ): array {
        $savedName = Contact::where('user_id', $recipient->id)
            ->where('contact_user_id', $sender->id)
            ->value('saved_name');

        $senderName = $savedName ?: $sender->name;

        if ($isGroup) {
            return [
                $chat->name ?: 'Group',
                $senderName . ': ' . $preview,
                $senderName,
            ];
        }

        return [$senderName, $preview, $senderName];
    }

    /**
     * Unread total for a user, or null if it couldn't be calculated.
     */
    private function unreadFor(int $userId): ?int
    {
        try {
            return UnreadService::forUser($userId);
        } catch (\Throwable $e) {
            Log::warning('Could not count unread messages', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}