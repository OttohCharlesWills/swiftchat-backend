<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use App\Events\NewMessage;
use Illuminate\Support\Facades\Validator;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;
use Kreait\Laravel\Firebase\Facades\Firebase;
use Cloudinary\Cloudinary as CloudinarySDK;

class MessageController extends Controller
{
    // List messages in a chat (paginated)
    public function index(Request $request, $chatId)
    {
        $chat = Chat::findOrFail($chatId);
        $this->authorizeParticipant($request, $chat);

        $messages = $chat->messages()
            ->with('sender')
            ->orderBy('created_at', 'desc')
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

        $message->load('sender');

        broadcast(new NewMessage($message));

        $this->sendPushToOtherParticipants($request, $chat, $message);

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

    // Push a notification to every other participant with a stored FCM token.
    // NOTE: I'm pulling user_id values off $chat->participants() the same way
    // markRead() does, then loading each User separately. If `participants()`
    // is a relation to a pivot/ChatParticipant model that already has a
    // `user` relationship defined, you can simplify this to
    // ->with('user')->get()->pluck('user') instead — tell me if so and I'll
    // tighten it up.
    private function sendPushToOtherParticipants(Request $request, Chat $chat, Message $message): void
    {
        $recipientIds = $chat->participants()
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
                $cloudMessage = CloudMessage::withTarget('token', $recipient->fcm_token)
                    ->withNotification(FirebaseNotification::create(
                        $request->user()->name,
                        $message->type === 'text' ? $message->body : ucfirst($message->type)
                    ))
                    ->withData(['chat_id' => (string) $chat->id]);

                Firebase::messaging()->send($cloudMessage);
            } catch (\Throwable $e) {
                // don't let a push failure break message sending
                \Log::warning('FCM push failed', ['user_id' => $recipient->id, 'error' => $e->getMessage()]);
            }
        }
    }

    public function uploadAttachment(Request $request, $chatId)
    {
        $chat = Chat::findOrFail($chatId);
        $this->authorizeParticipant($request, $chat);

        $request->validate([
            'file' => 'required|file|max:10240', // 10MB max
        ]);

        $uploaded = cloudinary()->upload($request->file('file')->getRealPath(), [
            'folder' => 'chats',
        ]);

        return response()->json([
            'url' => $uploaded->getSecurePath(),
        ]);
    }
}