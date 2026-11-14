<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\ScheduledMessage;
use App\Services\ScheduledMessageSender;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ScheduledMessageController extends Controller
{
    private const MAX_PENDING = 50;
    private const MAX_DAYS_AHEAD = 60;

    // GET /chats/{chatId}/scheduled-messages
    public function index(Request $request, $chatId)
    {
        $this->assertMember($request, $chatId);

        $items = ScheduledMessage::where('chat_id', $chatId)
            ->where('sender_id', $request->user()->id)
            ->where('status', 'pending')
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn ($m) => $this->present($m))
            ->values();

        return response()->json(['scheduled' => $items]);
    }

    // POST /chats/{chatId}/scheduled-messages   { body, scheduled_at }
    public function store(Request $request, $chatId)
    {
        $this->assertMember($request, $chatId);

        $v = Validator::make($request->all(), [
            'body' => 'required|string|max:4000',
            'scheduled_at' => 'required|date',
        ]);

        if ($v->fails()) {
            return response()->json(['message' => $v->errors()->first()], 422);
        }

        $at = $this->checkTime($request->scheduled_at);

        if (is_string($at)) {
            return response()->json(['message' => $at], 422);
        }

        $pending = ScheduledMessage::where('sender_id', $request->user()->id)
            ->where('status', 'pending')
            ->count();

        if ($pending >= self::MAX_PENDING) {
            return response()->json([
                'message' => 'You can have up to ' . self::MAX_PENDING . ' scheduled messages.',
            ], 422);
        }

        $item = ScheduledMessage::create([
            'chat_id' => (int) $chatId,
            'sender_id' => $request->user()->id,
            'body' => trim($request->body),
            'scheduled_at' => $at,
            'status' => 'pending',
        ]);

        return response()->json(['scheduled' => $this->present($item)], 201);
    }

    // POST /scheduled-messages/{id}/update   { body?, scheduled_at? }
    public function update(Request $request, $id)
    {
        $item = $this->mine($request, $id);

        $v = Validator::make($request->all(), [
            'body' => 'sometimes|required|string|max:4000',
            'scheduled_at' => 'sometimes|required|date',
        ]);

        if ($v->fails()) {
            return response()->json(['message' => $v->errors()->first()], 422);
        }

        if ($request->has('scheduled_at')) {
            $at = $this->checkTime($request->scheduled_at);

            if (is_string($at)) {
                return response()->json(['message' => $at], 422);
            }

            $item->scheduled_at = $at;
        }

        if ($request->has('body')) {
            $item->body = trim($request->body);
        }

        $item->save();

        return response()->json(['scheduled' => $this->present($item)]);
    }

    // POST /scheduled-messages/{id}/cancel
    public function cancel(Request $request, $id)
    {
        $item = $this->mine($request, $id);

        $item->forceFill(['status' => 'cancelled'])->save();

        return response()->json(['message' => 'Cancelled.']);
    }

    // POST /scheduled-messages/{id}/send-now
    public function sendNow(Request $request, $id)
    {
        $item = $this->mine($request, $id);

        $ok = ScheduledMessageSender::send($item);

        if (!$ok) {
            return response()->json(['message' => 'Could not send it.'], 422);
        }

        return response()->json(['message' => 'Sent.']);
    }

    // ------------------------------------------------------------

    /** Valid time -> Carbon (in the app timezone). Problem -> a message. */
    private function checkTime(string $value): Carbon|string
    {
        try {
            $at = Carbon::parse($value);
        } catch (\Throwable $e) {
            return 'That time is not valid.';
        }

        if ($at->lt(now()->addSeconds(30))) {
            return 'Pick a time in the future.';
        }

        if ($at->gt(now()->addDays(self::MAX_DAYS_AHEAD))) {
            return 'You can schedule up to ' . self::MAX_DAYS_AHEAD . ' days ahead.';
        }

        // The database stores time in the app timezone.
        return $at->setTimezone(config('app.timezone'));
    }

    private function mine(Request $request, $id): ScheduledMessage
    {
        $item = ScheduledMessage::where('id', $id)
            ->where('sender_id', $request->user()->id)
            ->where('status', 'pending')
            ->firstOrFail();

        return $item;
    }

    private function assertMember(Request $request, $chatId): void
    {
        $chat = Chat::findOrFail($chatId);

        abort_unless(
            $chat->participants()->where('user_id', $request->user()->id)->exists(),
            403,
            'You are not part of this chat.'
        );
    }

    private function present(ScheduledMessage $m): array
    {
        return [
            'id' => $m->id,
            'chat_id' => $m->chat_id,
            'body' => $m->body,
            'scheduled_at' => $m->scheduled_at->toIso8601String(),
            'status' => $m->status,
        ];
    }
}