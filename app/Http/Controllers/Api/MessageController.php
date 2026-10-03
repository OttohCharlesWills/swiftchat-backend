<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageDeleted;
use App\Events\NewMessage;
use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sticker;
use App\Models\Update;
use App\Models\User;
use App\Services\SupabaseService;
use App\Services\UnreadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;
use Kreait\Laravel\Firebase\Facades\Firebase;

class MessageController extends Controller
{
    /**
     * false = the push carries a normal "notification" block (works with
     *         every app version, including the old one).
     * true  = data-only push: the app draws the notification itself
     *         (profile picture + name + all messages stacked in one).
     *
     * Leave this FALSE until the new app version is installed on the
     * phones, then flip it to true.
     */
    private const DATA_ONLY_PUSH = false;

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
                'sticker',
            ])
            ->reorder('created_at', 'desc')
            ->paginate(30);

        // Voice notes are stored as a private path; hand the app a
        // short-lived playable link instead.
        $messages->getCollection()->each(fn ($m) => $this->presentMessage($m));

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
            'body' => 'required_without_all:attachment_path,sticker_id|string|nullable',
            'type' => 'nullable|in:text,image,video,file,audio,sticker',
            'attachment_path' => 'nullable|string',
            'reply_to_id' => 'nullable|exists:messages,id',
            'duration_seconds' => 'nullable|integer|min:0|max:3600',

            // The sticker's uuid (what the sticker API returns as "id").
            'sticker_id' => 'required_if:type,sticker|nullable|string|exists:stickers,uuid',

            // Set when this message is a private reply to someone's Update.
            'update_uuid' => 'nullable|string|exists:updates,uuid',
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
        // REPLY TO AN UPDATE (private comment)
        // --------------------------------------------------------

        $updatePreview = $request->filled('update_uuid')
            ? $this->buildUpdatePreview($request, $chat)
            : null;

        // --------------------------------------------------------
        // STICKER
        // --------------------------------------------------------

        // Sending a sticker_id always makes it a sticker message.
        $type = $request->filled('sticker_id')
            ? 'sticker'
            : ($request->type ?? 'text');

        $sticker = null;

        if ($type === 'sticker') {
            $sticker = Sticker::where('uuid', $request->sticker_id)
                ->whereHas('pack', fn ($q) => $q->active())
                ->first();

            if (!$sticker) {
                return response()->json([
                    'message' => 'This sticker is no longer available.',
                ], 422);
            }
        }

        // --------------------------------------------------------
        // CREATE MESSAGE
        // --------------------------------------------------------

        $message = Message::create([
            'chat_id' => $chat->id,
            'sender_id' => $request->user()->id,
            'body' => $type === 'sticker' ? null : $request->body,
            'type' => $type,
            'attachment_path' => $request->attachment_path,
            'sticker_id' => $sticker?->id,
            'reply_to_id' => $request->reply_to_id,
            'update_preview' => $updatePreview,
            'duration_seconds' => $type === 'audio'
                ? $request->duration_seconds
                : null,
            'is_deleted' => false,
            'deleted_for_user_ids' => null,
        ]);

        // Keep the sender's "recent stickers" list up to date.
        if ($sticker) {
            try {
                $sticker->recordUseBy((int) $request->user()->id);
            } catch (\Throwable $e) {
                Log::warning('Recording recent sticker failed', [
                    'sticker_id' => $sticker->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Load relationships before broadcasting so the Flutter app
        // receives the sender/reply/sticker information immediately.
        $message->load([
            'sender',
            'replyTo.sender',
            'sticker',
        ]);

        // Voice notes: swap the stored path for a playable signed link
        // (response/broadcast only, nothing is saved).
        $this->presentMessage($message);

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
    // UPDATE PREVIEW (for private replies to an Update)
    // ============================================================

    /**
     * Checks that the sender may reply to this Update and returns the
     * small snapshot stored on the message (shown as a quoted card
     * above the reply in the chat).
     */
    private function buildUpdatePreview(Request $request, Chat $chat): array
    {
        $viewerId = (int) $request->user()->id;

        $update = Update::with('user')
            ->active()
            ->where('uuid', $request->update_uuid)
            ->first();

        abort_if(
            !$update,
            404,
            'This update is no longer available.'
        );

        abort_if(
            (int) $update->user_id === $viewerId,
            422,
            'You cannot reply to your own update.'
        );

        abort_if(
            ($chat->type ?? 'private') === 'group',
            422,
            'Update replies go to a private chat.'
        );

        // The chat must be with the person who posted the Update.
        $ownerInChat = $chat
            ->participants()
            ->where('user_id', $update->user_id)
            ->exists();

        abort_unless(
            $ownerInChat,
            422,
            'This chat is not with the owner of that update.'
        );

        // Same visibility rules as viewing the Update itself.
        $isContact = DB::table('contacts')
            ->where('user_id', $viewerId)
            ->where('contact_user_id', $update->user_id)
            ->where('is_blocked', false)
            ->exists();

        $isBlocked = DB::table('update_blocked_contacts')
            ->where('user_id', $update->user_id)
            ->where('blocked_user_id', $viewerId)
            ->exists();

        abort_if(
            !$isContact || $isBlocked,
            403,
            'You cannot reply to this update.'
        );

        // Small picture for the quoted card. For videos, Cloudinary
        // returns a still frame when the file extension is .jpg.
        $thumb = null;

        if ($update->cloudinary_url) {
            $thumb = $update->type === 'video'
                ? preg_replace('/\.[a-z0-9]+$/i', '.jpg', $update->cloudinary_url)
                : $update->cloudinary_url;
        }

        return [
            'update_uuid' => $update->uuid,
            'type' => $update->type,
            'caption' => $update->caption
                ? Str::limit($update->caption, 120)
                : null,
            'thumb_url' => $thumb,
            'owner_id' => (int) $update->user_id,
            'owner_name' => $update->user->name ?? null,
        ];
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
            'unread_count' => UnreadService::forUser((int) $authId),
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
            // A deleted sticker message no longer points at a sticker.
            'sticker_id' => null,
            // The quoted Update card goes too.
            'update_preview' => null,
        ]);

        // --------------------------------------------------------
        // DELETE VOICE FILE FROM SUPABASE
        // --------------------------------------------------------

        if ($voiceUrl) {
            try {
                $this->supabase->delete($voiceUrl);
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

        // The service returns only the private storage path. "url" keeps
        // the same key the app already reads (it sends this value back as
        // attachment_path when the message is created); signed_url is a
        // temporary link if the app wants to play it straight away.
        return response()->json([
            'url' => $uploaded['path'],
            'path' => $uploaded['path'],
            'signed_url' => $this->supabase->playableUrl($uploaded['path']),
        ]);
    }

    // ============================================================
    // PRESENT MESSAGE (voice notes -> playable link)
    // ============================================================

    /**
     * For voice notes the database holds a private storage path, which the
     * app can't play. In the JSON sent to the app (list, send response and
     * realtime) it is replaced by a short-lived signed URL. Nothing is
     * written back to the database.
     */
    private function presentMessage(Message $message): Message
    {
        if (
            $message->type === 'audio'
            && !$message->is_deleted
            && $message->attachment_path
        ) {
            $url = $this->supabase->playableUrl($message->attachment_path);

            $message->setAttribute('attachment_url', $url);

            if ($url) {
                $message->setAttribute('attachment_path', $url);
            }
        }

        return $message;
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
        // log it so "why no notification?" is easy to answer.
        foreach ($recipientIds->diff($recipients->pluck('id')) as $missingId) {
            Log::info('Push skipped: recipient has no fcm_token', [
                'user_id' => $missingId,
                'chat_id' => $chat->id,
            ]);
        }

        $isGroup = ($chat->type ?? 'private') === 'group';

        // What the notification says for non-text messages.
        $preview = match ($message->type) {
            'text'    => $message->body ?? 'New message',
            'audio'   => 'Voice message',
            'image'   => 'Photo',
            'video'   => 'Video',
            'file'    => 'Document',
            'sticker' => 'Sticker',
            default   => ucfirst((string) $message->type),
        };

        // A private reply to someone's Update says so.
        if (!empty($message->update_preview)) {
            $preview = 'Replied to your update: ' . $preview;
        }

        foreach ($recipients as $recipient) {
            try {
                // Show the name the RECIPIENT saved this person as
                // (falls back to the person's own profile name).
                $savedName = Contact::where('user_id', $recipient->id)
                    ->where('contact_user_id', $sender->id)
                    ->value('saved_name');

                $senderName = $savedName ?: $sender->name;

                // Private chat:  "marolizzy"        / "hello"
                // Group chat:    "Group name"       / "marolizzy: hello"
                $title = $isGroup ? ($chat->name ?: 'Group') : $senderName;
                $body = $isGroup ? ($senderName . ': ' . $preview) : $preview;

                // Total unread for this person — the number on the
                // app icon.
                $unread = UnreadService::forUser((int) $recipient->id);

                $data = [
                    'type' => 'chat_message',
                    'chat_id' => (string) $chat->id,
                    'chat_type' => $isGroup ? 'group' : 'private',
                    'chat_name' => $isGroup
                        ? (string) ($chat->name ?? '')
                        : (string) $senderName,
                    'message_id' => (string) $message->id,
                    // NOTE: "message_type" is a reserved key in FCM
                    // data payloads and makes the send fail, so this
                    // one is called content_type.
                    'content_type' => (string) $message->type,
                    'sender_id' => (string) $sender->id,
                    'other_user_id' => (string) $sender->id,
                    'sender_name' => (string) $senderName,
                    'sender_avatar' => (string) ($sender->avatar_url ?? ''),
                    'badge' => (string) $unread,
                ];

                if (self::DATA_ONLY_PUSH) {
                    // The app builds the notification itself, so the
                    // text travels in the data. Android gets NO
                    // notification block.
                    $data['body'] = $preview;

                    $cloudMessage = CloudMessage::withTarget(
                        'token',
                        $recipient->fcm_token
                    )
                        ->withData($data)
                        ->withAndroidConfig(
                            AndroidConfig::fromArray([
                                'priority' => 'high',
                            ])
                        )
                        // iPhones can't build their own notification
                        // from a data message, so they still get a
                        // normal alert, grouped by chat.
                        ->withApnsConfig(
                            ApnsConfig::fromArray([
                                'headers' => ['apns-priority' => '10'],
                                'payload' => [
                                    'aps' => [
                                        'alert' => [
                                            'title' => $title,
                                            'body' => $body,
                                        ],
                                        'badge' => $unread,
                                        'sound' => 'default',
                                        'thread-id' => 'chat_' . $chat->id,
                                    ],
                                ],
                            ])
                        );
                } else {
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
                                'notification' => [
                                    'channel_id' => 'chat_messages',
                                    'sound' => 'default',
                                    // One notification per chat, updated
                                    // in place instead of piling up.
                                    'tag' => 'chat_' . $chat->id,
                                    // Number on the app icon for
                                    // launchers that support it.
                                    'notification_count' => $unread,
                                ],
                            ])
                        )
                        ->withApnsConfig(
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
                // Clear it so we stop sending to it; the app registers a
                // fresh one the next time it opens.
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
}