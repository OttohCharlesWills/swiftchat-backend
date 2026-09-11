<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ChatController extends Controller
{
    // List all chats the authenticated user is part of
    public function index(Request $request)
    {
        $chats = $request->user()->belongsToMany(Chat::class, 'chat_participants')
            ->with(['latestMessage.sender', 'users'])
            ->get();

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
        $otherId = $request->user_id;

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