<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MessageController extends Controller
{
    // List messages in a chat (paginated)
    public function index(Request $request, $chatId)
    {
        $chat = Chat::findOrFail($chatId);
        $this->authorizeParticipant($request, $chat);

        $messages = $chat->messages()
            ->with('sender')
            ->latest()
            ->paginate(30);

        return response()->json($messages);
    }

    // Send a message
    public function store(Request $request, $chatId)
    {
        $chat = Chat::findOrFail($chatId);
        $this->authorizeParticipant($request, $chat);

        $validator = Validator::make($request->all(), [
            'body'            => 'required_without:attachment_path|string|nullable',
            'type'            => 'in:text,image,video,file,audio',
            'attachment_path' => 'nullable|string',
            'reply_to_id'     => 'nullable|exists:messages,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $message = Message::create([
            'chat_id'         => $chat->id,
            'sender_id'       => $request->user()->id,
            'body'            => $request->body, // gets encrypted automatically via the model
            'type'            => $request->type ?? 'text',
            'attachment_path' => $request->attachment_path,
            'reply_to_id'     => $request->reply_to_id,
        ]);

        return response()->json($message->load('sender'), 201);
    }

    // Mark chat as read up to now
    public function markRead(Request $request, $chatId)
    {
        $chat = Chat::findOrFail($chatId);

        $chat->participants()
            ->where('user_id', $request->user()->id)
            ->update(['last_read_at' => now()]);

        return response()->json(['message' => 'Chat marked as read.']);
    }

    private function authorizeParticipant(Request $request, Chat $chat): void
    {
        $isParticipant = $chat->participants()->where('user_id', $request->user()->id)->exists();

        abort_unless($isParticipant, 403, 'You are not part of this chat.');
    }
}