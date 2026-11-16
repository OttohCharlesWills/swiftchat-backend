<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageDeleted;
use App\Events\NewMessage;
use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\MessageView;
use App\Models\Sticker;
use App\Models\Update;
use App\Models\User;
use App\Services\SupabaseService;
use App\Services\UnreadService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        // How many messages to send (the app asks for 30, or up to 100 when
        // it jumps to an old message found by search).
        $perPage = min(max((int) $request->query('per_page', 30), 1), 100);

        $query = $chat->messages()
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
                'reactions.user:id,name',
                'views',
            ]);

        // NEWER than a message: used after jumping to an old message, to load
        // the messages that come after it. Answer is newest first, like the
        // normal list.
        if ($request->filled('after_id')) {
            $newer = (clone $query)
                ->where('id', '>', (int) $request->query('after_id'))
                ->reorder('id', 'asc')
                ->limit($perPage)
                ->get()
                ->reverse()
                ->values();

            $newer->each(fn ($m) => $this->presentMessage($m));

            return response()->json(['data' => $newer]);
        }

        // OLDER than a message: the next page when scrolling up (the app
        // sends the id of the oldest message it already has).
        if ($request->filled('before_id')) {
            $query->where('id', '<', (int) $request->query('before_id'));
        }

        // Ordered by id (ids only ever grow), so every page continues exactly
        // where the last one stopped - nothing is skipped or shown twice.
        $messages = $query
            ->reorder('id', 'desc')
            ->paginate($perPage);

        // Voice notes are stored as a private path; hand the app a
        // short-lived playable link instead.
        $messages->getCollection()->each(fn ($m) => $this->presentMessage($m));

        return response()->json($messages);
    }

    // ============================================================
    // SEARCH IN A CHAT
    // ============================================================

    /**
     * Finds text messages that contain a word, from the newest message back
     * to the very first one.
     *
     * GET /chats/{chatId}/search?q=word&before_id=123
     *
     * Message text is stored encrypted, so the database cannot search it.
     * The messages are read in batches, decrypted here, and compared. To keep
     * one request fast it stops after 30 matches or about 4000 messages and
     * returns next_before_id, which the app sends back to continue.
     */
    public function search(Request $request, $chatId)
    {
        $chat = Chat::findOrFail($chatId);

        $this->authorizeParticipant($request, $chat);

        $request->validate([
            'q' => 'required|string|min:2|max:100',
            'before_id' => 'nullable|integer|min:1',
        ]);

        $needle = trim((string) $request->query('q'));

        $userId = (int) $request->user()->id;

        $cursor = $request->filled('before_id')
            ? (int) $request->query('before_id')
            : null;

        $limit = 30;
        $maxScan = 4000;
        $batch = 300;

        $scanned = 0;
        $matches = [];
        $exhausted = false;

        while (count($matches) < $limit && $scanned < $maxScan) {
            $rows = Message::where('chat_id', $chat->id)
                ->where('type', 'text')
                ->where('is_deleted', false)
                ->when($cursor, fn ($q) => $q->where('id', '<', $cursor))
                ->where(function ($q) use ($userId) {
                    $q->whereNull('deleted_for_user_ids')
                        ->orWhereJsonDoesntContain('deleted_for_user_ids', $userId);
                })
                ->with('sender:id,name')
                ->orderBy('id', 'desc')
                ->limit($batch)
                ->get();

            if ($rows->isEmpty()) {
                $exhausted = true;
                break;
            }

            foreach ($rows as $row) {
                $scanned++;
                $cursor = (int) $row->id;

                // The model decrypts the text when it is read.
                $body = (string) $row->body;

                if ($body !== '' && mb_stripos($body, $needle) !== false) {
                    $matches[] = [
                        'id' => (int) $row->id,
                        'body' => $body,
                        'sender_id' => (int) $row->sender_id,
                        'sender_name' => $row->sender->name ?? null,
                        'created_at' => $row->created_at?->toISOString(),
                    ];

                    if (count($matches) >= $limit) {
                        break;
                    }
                }
            }

            if ($rows->count() < $batch && count($matches) < $limit) {
                $exhausted = true;
                break;
            }
        }

        return response()->json([
            'results' => $matches,
            // true = there may be more (older) matches: ask again with
            // before_id = next_before_id.
            'has_more' => !$exhausted,
            'next_before_id' => $cursor,
        ]);
    }

    // ============================================================
    // SEND MESSAGE
    // ============================================================

    public function store(Request $request, $chatId)
    {
        $startedAt = microtime(true);

        $chat = Chat::findOrFail($chatId);

        $this->authorizeParticipant($request, $chat);

        // --------------------------------------------------------
        // FORWARDING
        // --------------------------------------------------------
        //
        // The app only sends forward_message_id. The server makes the
        // copy itself (see storeForwarded), so no new route is needed.
        //

        if ($request->filled('forward_message_id')) {
            return $this->storeForwarded($request, $chat);
        }

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

            // "View once": only for photos and voice notes.
            'view_once' => 'nullable|boolean',
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
                ->usableBy((int) $request->user()->id)
                ->first();

            if (!$sticker) {
                return response()->json([
                    'message' => 'This sticker is no longer available.',
                ], 422);
            }
        }

        // --------------------------------------------------------
        // VIEW ONCE (photos and voice notes only)
        // --------------------------------------------------------

        $viewOnce = in_array($type, ['image', 'audio'], true)
            && $request->boolean('view_once');

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

        // VIEW ONCE is set with forceFill, so it is saved even if
        // 'view_once' / 'purge_after' were not added to $fillable in the
        // Message model (Laravel would silently ignore them in create()).
        // Nobody opened it for 14 days -> its media is deleted.
        if ($viewOnce) {
            $message->forceFill([
                'view_once' => true,
                'purge_after' => now()->addDays(14),
            ])->save();
        }

        // SAFETY: the sender asked for "view once". If that could not be
        // saved (usually 'view_once' / 'purge_after' are missing from $fillable
        // in the Message model, or the migration was not run), the photo or
        // voice note would go out as a NORMAL message. Never allow that: undo
        // it and report the problem.
        if ($viewOnce && !$message->view_once) {
            Log::error(
                'View once was NOT saved. Add view_once and purge_after to '
                . '$fillable in the Message model, and run the migration.',
                ['message_id' => $message->id]
            );

            $message->delete();

            return response()->json([
                'message' => 'View once is not set up on the server.',
            ], 500);
        }

        // Load relationships before broadcasting so the Flutter app
        // receives the sender/reply/sticker information immediately.
        $message->load([
            'sender',
            'replyTo.sender',
            'sticker',
            'reactions.user:id,name',
            'views',
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
        // EVERYTHING SLOW HAPPENS AFTER THE ANSWER IS SENT
        // --------------------------------------------------------
        //
        // The sender's phone only needs "saved". The push notification
        // (a call to Google, one per person, plus unread counts) used to
        // run BEFORE the answer and made every send feel slow. It now
        // runs right after the response has gone out.
        //

        $senderId = (int) $request->user()->id;

        app()->terminating(function () use ($request, $chat, $message, $sticker, $senderId) {
            // Keep the sender's "recent stickers" list up to date.
            if ($sticker) {
                try {
                    $sticker->recordUseBy($senderId);
                } catch (\Throwable $e) {
                    Log::warning('Recording recent sticker failed', [
                        'sticker_id' => $sticker->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            try {
                $this->sendPushToOtherParticipants($request, $chat, $message);
            } catch (\Throwable $e) {
                Log::warning('Push after send failed', [
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        $tookMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($tookMs > 700) {
            Log::warning('Slow message send', [
                'ms' => $tookMs,
                'chat_id' => $chat->id,
                'type' => $type,
            ]);
        }

        return response()->json($message, 201);
    }

    // ============================================================
    // FORWARD A MESSAGE
    // ============================================================

    /**
     * Makes a copy of an existing message in another chat. The app only
     * sends the id of the message being forwarded; everything else is
     * copied here, so a voice note keeps its private file and nobody can
     * forward a message they are not allowed to see.
     */
    private function storeForwarded(Request $request, Chat $chat)
    {
        $request->validate([
            'forward_message_id' => 'required|integer|exists:messages,id',
        ]);

        $userId = (int) $request->user()->id;

        $source = Message::findOrFail($request->forward_message_id);

        // "View once" messages can never be forwarded.
        if ($source->view_once) {
            return response()->json([
                'message' => 'View once messages cannot be forwarded.',
            ], 422);
        }

        // You must be in the chat the message came from.
        $inSourceChat = Chat::findOrFail($source->chat_id)
            ->participants()
            ->where('user_id', $userId)
            ->exists();

        abort_unless($inSourceChat, 403, 'You cannot forward this message.');

        $deletedFor = array_map('intval', $source->deleted_for_user_ids ?? []);

        if ($source->is_deleted || in_array($userId, $deletedFor, true)) {
            return response()->json([
                'message' => 'This message is no longer available.',
            ], 422);
        }

        $message = Message::create([
            'chat_id' => $chat->id,
            'sender_id' => $userId,
            'body' => $source->type === 'text' ? $source->body : null,
            'type' => $source->type,
            'attachment_path' => $source->attachment_path,
            'sticker_id' => $source->sticker_id,
            'duration_seconds' => $source->duration_seconds,
            'reply_to_id' => null,
            'update_preview' => null,
            'is_deleted' => false,
            'deleted_for_user_ids' => null,
        ]);

        // Mark it as forwarded with a plain UPDATE, so it is saved even if
        // 'is_forwarded' is not in $fillable. If the column does not exist yet
        // (migration not run) the forward still goes out, just without the
        // "Forwarded" label - and the reason is written to the log.
        try {
            Message::whereKey($message->id)->update(['is_forwarded' => true]);

            // Pick up the saved value so it is part of the answer.
            $message->refresh();
        } catch (\Throwable $e) {
            Log::warning(
                'Could not save is_forwarded. Add the column: '
                . 'ALTER TABLE messages ADD COLUMN is_forwarded TINYINT(1) NOT NULL DEFAULT 0',
                ['message_id' => $message->id, 'error' => $e->getMessage()]
            );
        }

        $message->load(['sender', 'replyTo.sender', 'sticker', 'reactions.user:id,name', 'views']);
        $this->presentMessage($message);

        try {
            broadcast(new NewMessage($message));
        } catch (\Throwable $e) {
            Log::warning('Pusher forward broadcast failed', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        app()->terminating(function () use ($request, $chat, $message) {
            try {
                $this->sendPushToOtherParticipants($request, $chat, $message);
            } catch (\Throwable $e) {
                Log::warning('Push after forward failed', [
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

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

        // A forwarded copy of this voice note uses the SAME file. If any
        // other message still points at it, keep the file so the copies
        // keep playing.
        if (
            $voiceUrl
            && Message::where('attachment_path', $voiceUrl)
                ->where('id', '!=', $message->id)
                ->where('is_deleted', false)
                ->exists()
        ) {
            $voiceUrl = null;
        }

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
        // VIEW ONCE: the photo / voice note is never put in the message JSON
        // (list, send answer or realtime). The receiver only gets a link at
        // the moment they open it (see openViewOnce). Never save() this
        // instance afterwards - the path is blanked in memory only.
        if ($message->view_once && !$message->is_deleted) {
            $message->setAttribute('has_media', !empty($message->attachment_path));
            $message->setAttribute('attachment_path', null);
            $message->setAttribute('attachment_url', null);

            return $message;
        }

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
    // EMOJI REACTION
    // ============================================================

    /**
     * Sets, changes or removes the current user's emoji reaction.
     *
     * POST /chats/{chatId}/messages/{messageId}/reaction   { "emoji": "❤️" }
     *
     * An empty or missing emoji removes the reaction. Everyone in the chat
     * sees the change live (same realtime event as an edited message).
     */
    public function react(Request $request, $chatId, $messageId)
    {
        $chat = Chat::findOrFail($chatId);

        $this->authorizeParticipant($request, $chat);

        $request->validate([
            'emoji' => 'nullable|string|max:32',
        ]);

        $message = Message::where('chat_id', $chat->id)
            ->where('id', $messageId)
            ->firstOrFail();

        $userId = (int) $request->user()->id;

        $deletedFor = array_map('intval', $message->deleted_for_user_ids ?? []);

        if ($message->is_deleted || in_array($userId, $deletedFor, true)) {
            return response()->json([
                'message' => 'This message is no longer available.',
            ], 422);
        }

        // What this person had reacted with before (to notify only when the
        // reaction really changed).
        $previousEmoji = MessageReaction::where('message_id', $message->id)
            ->where('user_id', $userId)
            ->value('emoji');

        $emoji = trim((string) $request->input('emoji', ''));

        if ($emoji === '') {
            MessageReaction::where('message_id', $message->id)
                ->where('user_id', $userId)
                ->delete();
        } else {
            if (mb_strlen($emoji) > 16) {
                return response()->json([
                    'message' => 'That is not a valid reaction.',
                ], 422);
            }

            MessageReaction::updateOrCreate(
                ['message_id' => $message->id, 'user_id' => $userId],
                ['emoji' => $emoji]
            );
        }

        $message->load([
            'sender',
            'replyTo.sender',
            'sticker',
            'reactions.user:id,name',
            'views',
        ]);

        $this->presentMessage($message);

        try {
            broadcast(new NewMessage($message));
        } catch (\Throwable $e) {
            Log::warning('Pusher reaction broadcast failed', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Tell the author "X reacted to your message" (not when the reaction
        // was removed or is the same as before). Sent after the answer.
        if ($emoji !== '' && $emoji !== $previousEmoji) {
            app()->terminating(function () use ($request, $chat, $message, $emoji) {
                try {
                    $this->sendReactionPush($request, $chat, $message, $emoji);
                } catch (\Throwable $e) {
                    Log::warning('Push after reaction failed', [
                        'message_id' => $message->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        }

        return response()->json([
            'message_id' => $message->id,
            'reactions' => $message->reactions,
        ]);
    }

    // ============================================================
    // VIEW ONCE: OPEN
    // ============================================================

    /**
     * The receiver taps a "view once" photo / voice note.
     *
     * POST /chats/{chatId}/messages/{messageId}/view-once
     *
     * The view is recorded FIRST (unique per person per message), so a
     * second tap, a second phone or a retry always gets "already opened".
     * Only the first request receives the link to the media. Once every
     * receiver has opened it, the media is deleted from storage a couple of
     * minutes later (the delay lets the phone finish loading it).
     */
    public function openViewOnce(Request $request, $chatId, $messageId)
    {
        $chat = Chat::findOrFail($chatId);

        $this->authorizeParticipant($request, $chat);

        // Opportunistic clean-up of media that is due for deletion.
        $this->purgeDueViewOnce();

        $userId = (int) $request->user()->id;

        $message = Message::where('chat_id', $chat->id)
            ->where('id', $messageId)
            ->firstOrFail();

        if (!$message->view_once || $message->is_deleted) {
            return response()->json([
                'message' => 'This is not a view once message.',
            ], 404);
        }

        $deletedFor = array_map('intval', $message->deleted_for_user_ids ?? []);

        if (in_array($userId, $deletedFor, true)) {
            return response()->json([
                'message' => 'This message is no longer available.',
            ], 404);
        }

        // The sender can never open their own view once message.
        if ((int) $message->sender_id === $userId) {
            return response()->json([
                'message' => 'You cannot open your own view once message.',
            ], 403);
        }

        $path = $message->attachment_path;

        $expired = empty($path)
            || (
                $message->purge_after
                && Carbon::parse($message->purge_after)->isPast()
            );

        if ($expired) {
            return response()->json([
                'message' => 'This message has expired.',
            ], 410);
        }

        // Make the link BEFORE recording the view, so a failure here does
        // not use up the person's one view.
        $url = $message->type === 'audio'
            ? $this->supabase->playableUrl($path)
            : $path;

        if (empty($url)) {
            return response()->json([
                'message' => 'Could not prepare this message. Try again.',
            ], 500);
        }

        // Record the view. The unique key (message_id, user_id) is what makes
        // "once" real, even if two requests arrive at the same moment.
        try {
            MessageView::create([
                'message_id' => $message->id,
                'user_id' => $userId,
                'viewed_at' => now(),
            ]);
        } catch (QueryException $e) {
            if (str_starts_with((string) $e->getCode(), '23')) {
                return response()->json([
                    'message' => 'You already opened this message.',
                ], 410);
            }

            throw $e;
        }

        // Everybody who can open it has opened it -> delete the media soon.
        $receivers = $chat->participants()
            ->where('user_id', '!=', $message->sender_id)
            ->count();

        $opened = MessageView::where('message_id', $message->id)->count();

        if ($opened >= $receivers) {
            $message->forceFill(['purge_after' => now()->addMinutes(2)])->save();
        }

        $duration = $message->duration_seconds;
        $type = $message->type;

        // Tell everyone (the sender sees "Opened").
        $message->load([
            'sender',
            'replyTo.sender',
            'sticker',
            'reactions.user:id,name',
            'views',
        ]);

        $this->presentMessage($message);

        try {
            broadcast(new NewMessage($message));
        } catch (\Throwable $e) {
            Log::warning('Pusher view-once broadcast failed', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()
            ->json([
                'url' => $url,
                'type' => $type,
                'duration_seconds' => $duration,
            ])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Deletes the media of view once messages that are due (everyone opened
     * them, or 14 days passed). Called from openViewOnce, and you can also
     * call it from a scheduled command if you have cron.
     */
    private function purgeDueViewOnce(int $limit = 20): void
    {
        try {
            Message::where('view_once', true)
                ->whereNotNull('purge_after')
                ->where('purge_after', '<=', now())
                ->whereNotNull('attachment_path')
                ->limit($limit)
                ->get()
                ->each(fn (Message $m) => $this->purgeViewOnceMedia($m));
        } catch (\Throwable $e) {
            Log::warning('View once purge failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function purgeViewOnceMedia(Message $message): void
    {
        $path = $message->attachment_path;

        if (!$path) {
            return;
        }

        try {
            if ($message->type === 'audio') {
                $this->supabase->delete($path);
            } else {
                $publicId = $this->cloudinaryPublicId($path);

                if ($publicId) {
                    cloudinary()->uploadApi()->destroy($publicId);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('View once media delete failed', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        $message->update(['attachment_path' => null]);
    }

    /**
     * https://res.cloudinary.com/<cloud>/image/upload/v123/chats/abc.jpg
     * -> chats/abc
     *
     * (The stored url is the plain secure_url, without transformations.)
     */
    private function cloudinaryPublicId(string $url): ?string
    {
        if (!preg_match('#/upload/(?:v\d+/)?(.+?)\.[A-Za-z0-9]+$#', $url, $m)) {
            return null;
        }

        return $m[1];
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

    public function update(
        Request $request,
        $chatId,
        $messageId
    ) {
        $chat = Chat::findOrFail($chatId);

        $this->authorizeParticipant($request, $chat);

        $message = Message::where('chat_id', $chat->id)
            ->where('id', $messageId)
            ->firstOrFail();

        // Only the sender can edit.
        if ((int) $message->sender_id !== (int) $request->user()->id) {
            return response()->json([
                'message' => 'You can only edit your own messages.',
            ], 403);
        }

        // Only plain text messages.
        if ($message->type !== 'text') {
            return response()->json([
                'message' => 'Only text messages can be edited.',
            ], 422);
        }

        if ($message->is_deleted) {
            return response()->json([
                'message' => 'This message was deleted.',
            ], 422);
        }

        // Same window as the app (_editWindow in chat_screen.dart).
        if ($message->created_at->lt(now()->subMinutes(15))) {
            return response()->json([
                'message' => 'You can only edit a message within 15 minutes of sending it.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'body' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $newBody = trim($request->body);

        if ($newBody === '') {
            return response()->json([
                'message' => 'A message cannot be empty.',
            ], 422);
        }

        // Nothing changed -> keep it unmarked, just answer.
        // (store() saves plain text and the model encrypts it, so we
        // do the same here.)
        if ($newBody !== $message->body) {
            $message->update([
                'body' => $newBody,
                'is_edited' => true,
            ]);
        }

        // Same relations store() loads, so the app gets the same shape.
        $message->load([
            'sender',
            'replyTo.sender',
            'sticker',
            'reactions.user:id,name',
            'views',
        ]);

        // Realtime: the SAME event as a new message. The app already has
        // this id, sees is_edited = true, and swaps the text.
        // (No push notification for edits.)
        try {
            broadcast(new NewMessage($message));
        } catch (\Throwable $e) {
            Log::warning('Pusher edit broadcast failed', [
                'message_id' => $message->id,
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json($message);
    }

    // ============================================================
    // PUSH NOTIFICATION: SOMEONE REACTED TO YOUR MESSAGE
    // ============================================================

    /**
     * Tells the AUTHOR of a message that someone reacted to it
     * ("marolizzy: Reacted ❤️ to "hello""). Nobody else is notified, and
     * nobody is told about their own reaction.
     */
    private function sendReactionPush(
        Request $request,
        Chat $chat,
        Message $message,
        string $emoji
    ): void {
        $reactor = $request->user();

        // Never notify yourself.
        if ((int) $message->sender_id === (int) $reactor->id) {
            return;
        }

        $author = User::find($message->sender_id);

        if (!$author || empty($author->fcm_token)) {
            return;
        }

        $isGroup = ($chat->type ?? 'private') === 'group';

        // What the reaction is about. "View once" never shows what is inside.
        if ($message->view_once) {
            $about = $message->type === 'audio'
                ? 'your voice message'
                : 'your photo';
        } else {
            $about = match ($message->type) {
                'text'    => $message->body
                    ? '"' . Str::limit((string) $message->body, 40) . '"'
                    : 'your message',
                'image'   => 'your photo',
                'audio'   => 'your voice message',
                'video'   => 'your video',
                'file'    => 'your document',
                'sticker' => 'your sticker',
                default   => 'your message',
            };
        }

        // The name the AUTHOR saved the reactor as.
        $savedName = Contact::where('user_id', $author->id)
            ->where('contact_user_id', $reactor->id)
            ->value('saved_name');

        $reactorName = $savedName ?: $reactor->name;

        $title = $isGroup ? ($chat->name ?: 'Group') : $reactorName;

        $body = $isGroup
            ? $reactorName . ' reacted ' . $emoji . ' to ' . $about
            : 'Reacted ' . $emoji . ' to ' . $about;

        // A reaction does not add an unread message, but the badge number
        // is sent along like in every other push.
        $unread = UnreadService::forUser((int) $author->id);

        $data = [
            'type' => 'chat_message',
            'chat_id' => (string) $chat->id,
            'chat_type' => $isGroup ? 'group' : 'private',
            'chat_name' => $isGroup
                ? (string) ($chat->name ?? '')
                : (string) $reactorName,
            'message_id' => (string) $message->id,
            // Tells the app this is a reaction, not a new message.
            'content_type' => 'reaction',
            'reaction' => $emoji,
            'sender_id' => (string) $reactor->id,
            'other_user_id' => (string) $reactor->id,
            'sender_name' => (string) $reactorName,
            'sender_avatar' => (string) ($reactor->avatar_url ?? ''),
            'badge' => (string) $unread,
        ];

        try {
            if (self::DATA_ONLY_PUSH) {
                $data['body'] = $body;

                $cloudMessage = CloudMessage::withTarget('token', $author->fcm_token)
                    ->withData($data)
                    ->withAndroidConfig(AndroidConfig::fromArray([
                        'priority' => 'high',
                    ]))
                    ->withApnsConfig(ApnsConfig::fromArray([
                        'headers' => ['apns-priority' => '10'],
                        'payload' => [
                            'aps' => [
                                'alert' => ['title' => $title, 'body' => $body],
                                'badge' => $unread,
                                'sound' => 'default',
                                'thread-id' => 'chat_' . $chat->id,
                            ],
                        ],
                    ]));
            } else {
                $cloudMessage = CloudMessage::withTarget('token', $author->fcm_token)
                    ->withNotification(FirebaseNotification::create($title, $body))
                    ->withData($data)
                    ->withAndroidConfig(AndroidConfig::fromArray([
                        'priority' => 'high',
                        'notification' => [
                            'channel_id' => 'chat_messages',
                            'sound' => 'default',
                            // A different tag from the messages of the chat,
                            // so a reaction never replaces the last message
                            // notification.
                            'tag' => 'react_' . $message->id,
                            'notification_count' => $unread,
                        ],
                    ]))
                    ->withApnsConfig(ApnsConfig::fromArray([
                        'payload' => [
                            'aps' => [
                                'badge' => $unread,
                                'sound' => 'default',
                            ],
                        ],
                    ]));
            }

            Firebase::messaging()->send($cloudMessage);
        } catch (NotFound $e) {
            // Dead token (app uninstalled): clear it, like for messages.
            $author->update(['fcm_token' => null]);
        } catch (\Throwable $e) {
            Log::warning('Reaction push failed', [
                'user_id' => $author->id,
                'error_class' => get_class($e),
                'error' => $e->getMessage(),
            ]);
        }
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
            'sticker' => 'Sent a sticker 🏷️',
            default   => ucfirst((string) $message->type),
        };

        // View once never shows what is inside.
        if ($message->view_once) {
            $preview = $message->type === 'audio'
                ? 'View once voice message'
                : 'View once photo';
        }

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