<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ChatController extends Controller
{
    // List all chats the authenticated user is part of, newest activity first
    public function index(Request $request)
    {
        $authId = $request->user()->id;

        $chats = $request->user()->belongsToMany(Chat::class, 'chat_participants')
            ->withPivot('last_read_at')
            ->with(['latestMessage.sender', 'users'])
            // Sort by whichever is more recent: the chat's own updated_at
            // (bumped when a message is sent — confirm your Message model's
            // saving hook actually touches the parent chat's timestamp) or
            // its created_at, so brand-new chats with no messages yet still
            // sort correctly instead of falling to the bottom.
            ->orderByDesc('chats.updated_at')
            ->get()
            ->map(function ($chat) use ($authId) {
                if ($chat->type === 'private') {
                    $otherUser = $chat->users->firstWhere('id', '!=', $authId);
                    $chat->display_name = $otherUser?->name;
                    $chat->display_avatar = $otherUser?->avatar ?? null;
                } else {
                    $chat->display_name = $chat->name; // group chat name
                }

                // Unread = messages someone else sent, since you last read
                // this chat. Uses the same last_read_at pivot markRead()
                // already writes to, so it stays correct for group chats
                // too (unlike a single per-message read_at column, which
                // can't represent "read by me but not by others").
                $lastReadAt = $chat->pivot->last_read_at;

                $chat->unread_count = $chat->messages()
                    ->where('sender_id', '!=', $authId)
                    ->when($lastReadAt, fn ($q) => $q->where('created_at', '>', $lastReadAt))
                    ->count();

                return $chat;
            });

        return response()->json($chats);
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