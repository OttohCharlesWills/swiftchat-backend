<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Message;
use App\Services\UnreadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ChatController extends Controller
{
    // List all chats the authenticated user is part of, newest activity first
    public function index(Request $request)
    {
        $authId = (int) $request->user()->id;

        $chats = $request->user()->belongsToMany(Chat::class, 'chat_participants')
            ->withPivot('last_read_at')
            // latestMessage is NOT eager loaded here: it is worked out
            // below so a message you deleted "for me" never shows up as
            // the preview.
            ->with(['users'])
            // Sort by whichever is more recent: the chat's own updated_at
            // (bumped when a message is sent — confirm your Message model's
            // saving hook actually touches the parent chat's timestamp) or
            // its created_at, so brand-new chats with no messages yet still
            // sort correctly instead of falling to the bottom.
            ->orderByDesc('chats.updated_at')
            ->get();

        // --------------------------------------------------------
        // LAST MESSAGE PER CHAT (hiding what the user deleted for
        // themselves) — one query for all chats.
        // --------------------------------------------------------

        $latestIds = Message::query()
            ->whereIn('chat_id', $chats->pluck('id'))
            ->where(function ($query) use ($authId) {
                $query->whereNull('deleted_for_user_ids')
                    ->orWhereJsonDoesntContain('deleted_for_user_ids', $authId);
            })
            ->selectRaw('MAX(id) as id')
            ->groupBy('chat_id')
            ->pluck('id');

        $latestByChat = Message::with('sender')
            ->whereIn('id', $latestIds)
            ->get()
            ->keyBy('chat_id');

        // --------------------------------------------------------
        // UNREAD COUNT PER CHAT — one query for all chats, same rule
        // as the number on the app icon.
        // --------------------------------------------------------

        $unreadByChat = UnreadService::perChat($authId);

        $chats = $chats->map(function ($chat) use ($authId, $latestByChat, $unreadByChat) {
            if ($chat->type === 'private') {
                $otherUser = $chat->users->firstWhere('id', '!=', $authId);
                $chat->display_name = $otherUser?->name;
                $chat->display_avatar = $otherUser?->avatar_url;
            } else {
                $chat->display_name = $chat->name; // group chat name
            }

            // Last message shown under the chat name. Voice notes, photos,
            // videos, documents and stickers have no text body, so give
            // them a readable label — only in this response, nothing is
            // saved.
            $latest = $latestByChat->get($chat->id);

            if ($latest) {
                $preview = $this->previewFor($latest);

                if ($preview !== null) {
                    $latest->setAttribute('body', $preview);
                }
            }

            $chat->setRelation('latestMessage', $latest);

            $chat->unread_count = (int) ($unreadByChat[$chat->id] ?? 0);

            return $chat;
        });

        return response()->json($chats);
    }

    /**
     * Text to show in the chat list instead of an empty body.
     * Returns null for normal text messages (their own body is used).
     */
    private function previewFor(Message $message): ?string
    {
        if ($message->is_deleted) {
            return 'This message was deleted';
        }

        return match ($message->type) {
            'audio'   => '🎤 Voice message',
            'image'   => '📷 Photo',
            'video'   => '🎥 Video',
            'file'    => '📄 Document',
            'sticker' => '🏷️ Sticker',
            default   => null,
        };
    }

    // Start (or return existing) 1-on-1 chat with another user
    public function startPrivate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $authId = $request->user()->id;
        $otherId = (int) $request->user_id;
        $isSelfChat = $otherId === $authId;

        if ($isSelfChat) {
            // "Saved Messages" — a private chat with only you in it. Can't
            // reuse the two-sided lookup below (both conditions would be
            // identical and match ANY of your private chats), so look
            // specifically for a private chat with exactly one participant.
            $existing = Chat::where('type', 'private')
                ->whereHas('participants', fn ($q) => $q->where('user_id', $authId))
                ->withCount('participants')
                ->having('participants_count', 1)
                ->first();

            if ($existing) {
                return response()->json($existing->load('users'));
            }

            $chat = Chat::create([
                'type'       => 'private',
                'created_by' => $authId,
            ]);

            $chat->participants()->create(['user_id' => $authId]);

            return response()->json($chat->load('users'), 201);
        }

        // Check if a private chat already exists between these two users
        $existing = Chat::where('type', 'private')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $authId))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $otherId))
            ->first();

        if ($existing) {
            return response()->json($existing->load('users'));
        }

        $chat = Chat::create([
            'type'       => 'private',
            'created_by' => $authId,
        ]);

        $chat->participants()->createMany([
            ['user_id' => $authId],
            ['user_id' => $otherId],
        ]);

        return response()->json($chat->load('users'), 201);
    }

    // Create a group chat
    public function startGroup(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'               => 'required|string|max:255',
            'user_ids'           => 'required|array|min:1',
            'user_ids.*'         => 'exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $chat = Chat::create([
            'type'       => 'group',
            'name'       => $request->name,
            'created_by' => $request->user()->id,
        ]);

        $chat->participants()->create([
            'user_id' => $request->user()->id,
            'role'    => 'admin',
        ]);

        foreach ($request->user_ids as $userId) {
            if ($userId != $request->user()->id) {
                $chat->participants()->create(['user_id' => $userId]);
            }
        }

        return response()->json($chat->load('users'), 201);
    }
}