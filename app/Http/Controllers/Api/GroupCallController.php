<?php

namespace App\Http\Controllers\Api;

use App\Events\CallSignal;
use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\GroupCall;
use App\Models\GroupCallParticipant;
use App\Models\User;
use App\Services\IceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Laravel\Firebase\Facades\Firebase;

/**
 * Group voice calls, peer-to-peer ("mesh"): every phone connects directly
 * to every other phone in the call. The server only keeps track of who is
 * in the call and passes the small handshake messages (offer / answer /
 * network candidates) between phones. The audio never touches the server.
 *
 * Because every phone connects to every other one, keep calls small. The
 * limit is config('calls.group_max', 6).
 */
class GroupCallController extends Controller
{
    private const RELOAD = ['chat', 'starter', 'participants.user'];

    public function __construct(
        protected IceService $ice,
    ) {
    }

    // ============================================================
    // IS A CALL RUNNING IN THIS GROUP?  (for the "Join" banner)
    // ============================================================

    /** GET /chats/{chatId}/group-call */
    public function activeForChat(Request $request, $chatId)
    {
        $chat = Chat::findOrFail($chatId);
        $user = $request->user();

        $this->authorizeMember($user, $chat);

        GroupCall::expireStale();

        $call = GroupCall::active()
            ->where('chat_id', $chat->id)
            ->latest()
            ->first();

        return response()->json([
            'call' => $call ? $call->load(self::RELOAD)->toApi($user) : null,
        ]);
    }

    /** GET /group-calls/{call} */
    public function show(Request $request, GroupCall $call)
    {
        $user = $request->user();

        $this->authorizeMember($user, Chat::findOrFail($call->chat_id));

        $call->load(self::RELOAD);

        return response()->json(['call' => $call->toApi($user)]);
    }

    // ============================================================
    // START
    // ============================================================

    /** POST /group-calls   { chat_id } */
    public function start(Request $request)
    {
        $data = $request->validate([
            'chat_id' => 'required|integer|exists:chats,id',
        ]);

        $user = $request->user();
        $chat = Chat::findOrFail($data['chat_id']);

        $this->authorizeMember($user, $chat);

        abort_unless(
            ($chat->type ?? '') === 'group',
            422,
            'Group calls are only for group chats.'
        );

        Call::expireStale();
        GroupCall::expireStale();

        $memberIds = $chat->participants()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        // Lock the chat row so two people pressing "call" at the same
        // instant can't start two calls in the same group.
        $result = DB::transaction(function () use ($chat, $user, $memberIds) {
            Chat::whereKey($chat->id)->lockForUpdate()->first();

            $existing = GroupCall::active()
                ->where('chat_id', $chat->id)
                ->latest()
                ->first();

            if ($existing) {
                return ['existing' => $existing];
            }

            if ($this->isBusy($user)) {
                return ['busy' => true];
            }

            $call = GroupCall::create([
                'chat_id' => $chat->id,
                'started_by' => $user->id,
                'status' => 'active',
                'started_at' => now(),
            ]);

            foreach ($memberIds as $memberId) {
                $isStarter = $memberId === (int) $user->id;

                GroupCallParticipant::create([
                    'group_call_id' => $call->id,
                    'user_id' => $memberId,
                    'status' => $isStarter ? 'joined' : 'invited',
                    'joined_at' => $isStarter ? now() : null,
                ]);
            }

            return ['created' => $call];
        });

        // A call is already running in this group: just join it.
        if (isset($result['existing'])) {
            return $this->joinCall($request, $result['existing'], true);
        }

        if (isset($result['busy'])) {
            return response()->json(['message' => 'You are already in a call.'], 409);
        }

        $call = $result['created']->fresh(self::RELOAD);

        $inviteeIds = $memberIds
            ->reject(fn ($id) => $id === (int) $user->id)
            ->values()
            ->all();

        // Ring everyone else: realtime if their app is open, push if not.
        $this->broadcastTo($call, 'group_call.incoming', $inviteeIds);
        $this->pushIncoming($call, $inviteeIds);

        return response()->json([
            'call' => $call->toApi($user),
            'peers' => [],
            'ice_servers' => $this->ice->servers((int) $user->id),
        ], 201);
    }

    // ============================================================
    // JOIN
    // ============================================================

    /** POST /group-calls/{call}/join */
    public function join(Request $request, GroupCall $call)
    {
        return $this->joinCall($request, $call, false);
    }

    protected function joinCall(Request $request, GroupCall $call, bool $created)
    {
        $user = $request->user();

        $this->authorizeMember($user, Chat::findOrFail($call->chat_id));

        Call::expireStale();
        GroupCall::expireStale();

        if ($this->isBusy($user, $call)) {
            return response()->json(['message' => 'You are already in a call.'], 409);
        }

        $max = (int) config('calls.group_max', 6);

        // Row-locked: two people joining the last free place at the same
        // instant can't both get it.
        $result = DB::transaction(function () use ($call, $user, $max) {
            $fresh = GroupCall::whereKey($call->id)->lockForUpdate()->first();

            if (!$fresh || $fresh->status !== 'active') {
                return ['error' => 'This call has ended.'];
            }

            $part = GroupCallParticipant::where('group_call_id', $fresh->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            // Added to the group after the call started.
            if (!$part) {
                $part = GroupCallParticipant::create([
                    'group_call_id' => $fresh->id,
                    'user_id' => $user->id,
                    'status' => 'invited',
                ]);
            }

            // Already joined (e.g. the app restarted): fall through and
            // hand back the list of people so it can reconnect.
            if ($part->status !== 'joined') {
                $joined = GroupCallParticipant::where('group_call_id', $fresh->id)
                    ->where('status', 'joined')
                    ->count();

                if ($joined >= $max) {
                    return ['error' => "This call is full ($max people)."];
                }

                $part->fill([
                    'status' => 'joined',
                    'joined_at' => now(),
                    'left_at' => null,
                ])->save();
            }

            $peers = GroupCallParticipant::where('group_call_id', $fresh->id)
                ->where('status', 'joined')
                ->where('user_id', '!=', $user->id)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            return ['call' => $fresh, 'peers' => $peers];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 409);
        }

        $call = $result['call']->fresh(self::RELOAD);

        // Everyone already in the call sees who just joined.
        $this->broadcastTo($call, 'group_call.updated', $this->joinedIds($call));

        // Your other devices stop ringing.
        $this->pushCancelledTo($call, [(int) $user->id]);

        return response()->json([
            'call' => $call->toApi($user),
            'peers' => $result['peers'],
            'ice_servers' => $this->ice->servers((int) $user->id),
        ], $created ? 201 : 200);
    }

    // ============================================================
    // DECLINE
    // ============================================================

    /** POST /group-calls/{call}/decline */
    public function decline(Request $request, GroupCall $call)
    {
        $user = $request->user();

        $this->authorizeMember($user, Chat::findOrFail($call->chat_id));

        $part = GroupCallParticipant::where('group_call_id', $call->id)
            ->where('user_id', $user->id)
            ->first();

        if ($part && $part->status === 'invited') {
            $part->fill(['status' => 'declined'])->save();
        }

        $call = $call->fresh(self::RELOAD);

        $this->broadcastTo($call, 'group_call.updated', $this->joinedIds($call));
        $this->pushCancelledTo($call, [(int) $user->id]);

        return response()->json(['call' => $call->toApi($user)]);
    }

    // ============================================================
    // LEAVE
    // ============================================================

    /** POST /group-calls/{call}/leave */
    public function leave(Request $request, GroupCall $call)
    {
        $user = $request->user();

        $this->authorizeMember($user, Chat::findOrFail($call->chat_id));

        $ended = false;

        DB::transaction(function () use ($call, $user, &$ended) {
            $fresh = GroupCall::whereKey($call->id)->lockForUpdate()->first();

            if (!$fresh) {
                return;
            }

            $part = GroupCallParticipant::where('group_call_id', $fresh->id)
                ->where('user_id', $user->id)
                ->first();

            if ($part && $part->status === 'joined') {
                $part->fill(['status' => 'left', 'left_at' => now()])->save();
            } elseif ($part && $part->status === 'invited') {
                $part->fill(['status' => 'declined'])->save();
            }

            $stillJoined = GroupCallParticipant::where('group_call_id', $fresh->id)
                ->where('status', 'joined')
                ->count();

            // The last person left: the call is over.
            if ($fresh->status === 'active' && $stillJoined === 0) {
                $seconds = $fresh->started_at
                    ? (int) abs(now()->diffInSeconds($fresh->started_at))
                    : 0;

                $fresh->fill([
                    'status' => 'ended',
                    'ended_at' => now(),
                    'duration_seconds' => $seconds,
                    'end_reason' => 'everyone_left',
                ])->save();

                $ended = true;
            }
        });

        $call = $call->fresh(self::RELOAD);

        if ($ended) {
            // Tell everyone, including people who never picked up, so the
            // ringing stops.
            $this->broadcastTo(
                $call,
                'group_call.updated',
                $call->participants->pluck('user_id')->all()
            );

            $this->pushCancelledTo(
                $call,
                $call->participants->where('status', 'invited')->pluck('user_id')->all()
            );
        } else {
            $this->broadcastTo($call, 'group_call.updated', $this->joinedIds($call));
        }

        return response()->json(['call' => $call->toApi($user)]);
    }

    // ============================================================
    // WEBRTC HANDSHAKE
    // ============================================================

    /**
     * POST /group-calls/{call}/signal   { to, kind: offer|answer|candidate|restart, data }
     *
     * Passes one handshake message to ONE other person in the call.
     */
    public function signal(Request $request, GroupCall $call)
    {
        $user = $request->user();

        $data = $request->validate([
            'to' => 'required|integer',
            'kind' => 'required|in:offer,answer,candidate,restart',
            'data' => 'required|array',
        ]);

        abort_unless($call->status === 'active', 409, 'The call is over.');
        abort_if(strlen((string) json_encode($data['data'])) > 8000, 422, 'Signal too large.');

        $joined = $this->joinedIds($call);

        abort_unless(in_array((int) $user->id, $joined, true), 403, 'You are not in this call.');
        abort_unless(in_array((int) $data['to'], $joined, true), 409, 'That person is not in the call.');

        try {
            broadcast(new CallSignal((int) $data['to'], 'group_call.signal', [
                'call_id' => $call->uuid,
                'from' => (int) $user->id,
                'kind' => $data['kind'],
                'data' => $data['data'],
            ]));
        } catch (\Throwable $e) {
            Log::warning('Group call relay failed', [
                'call' => $call->uuid,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Could not reach that phone.'], 503);
        }

        return response()->json(['ok' => true]);
    }

    // ============================================================
    // HELPERS
    // ============================================================

    protected function authorizeMember(User $user, Chat $chat): void
    {
        $isMember = $chat->participants()
            ->where('user_id', $user->id)
            ->exists();

        abort_unless($isMember, 403, 'You are not part of this group.');
    }

    /** In a 1-to-1 call (ringing or connected), or joined in another group call. */
    protected function isBusy(User $user, ?GroupCall $except = null): bool
    {
        if (Call::activeFor((int) $user->id)->exists()) {
            return true;
        }

        $query = GroupCall::activeForUser((int) $user->id);

        if ($except) {
            $query->where('group_calls.id', '!=', $except->id);
        }

        return $query->exists();
    }

    protected function joinedIds(GroupCall $call): array
    {
        return GroupCallParticipant::where('group_call_id', $call->id)
            ->where('status', 'joined')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Realtime (Pusher) message to people whose app is open. */
    protected function broadcastTo(GroupCall $call, string $event, array $userIds): void
    {
        $call->loadMissing(self::RELOAD);

        foreach (array_unique(array_map('intval', $userIds)) as $id) {
            $viewer = $call->participants->firstWhere('user_id', $id)?->user;

            if (!$viewer) {
                continue;
            }

            try {
                broadcast(new CallSignal($id, $event, ['call' => $call->toApi($viewer)]));
            } catch (\Throwable $e) {
                Log::warning('Group call event failed', [
                    'call' => $call->uuid,
                    'user_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * High-priority data push so phones ring even when the app is closed.
     *
     * It deliberately uses type "incoming_call" (the same as 1-to-1 calls)
     * with group = 1, so the app's existing incoming-call notification
     * (full screen, Answer / Decline) works for group calls too.
     */
    protected function pushIncoming(GroupCall $call, array $inviteeIds): void
    {
        $starter = $call->starter;
        $chat = $call->chat;

        if (!$starter || empty($inviteeIds)) {
            return;
        }

        $users = User::whereIn('id', $inviteeIds)
            ->whereNotNull('fcm_token')
            ->get();

        // The name each invited person saved the caller under.
        $saved = Contact::whereIn('user_id', $inviteeIds)
            ->where('contact_user_id', $starter->id)
            ->pluck('saved_name', 'user_id');

        foreach ($users as $invitee) {
            $starterName = $saved[$invitee->id] ?? $starter->name;

            $this->sendPush($invitee, [
                'type' => 'incoming_call',
                'group' => '1',
                'call_id' => $call->uuid,
                'caller_id' => (string) $starter->id,
                // Shown as the title: the group's name.
                'caller_name' => (string) ($chat?->name ?: 'Group call'),
                // Shown in the text: who started it.
                'group_caller' => (string) $starterName,
                'caller_avatar' => '',
                'chat_id' => (string) $call->chat_id,
                'chat_name' => (string) ($chat?->name ?? ''),
                'ring_seconds' => (string) config('calls.ring_seconds', 45),
            ]);
        }
    }

    /** Makes the ringing stop on phones whose app is closed. */
    protected function pushCancelledTo(GroupCall $call, array $userIds): void
    {
        if (empty($userIds)) {
            return;
        }

        $users = User::whereIn('id', array_map('intval', $userIds))
            ->whereNotNull('fcm_token')
            ->get();

        foreach ($users as $user) {
            $this->sendPush($user, [
                'type' => 'call_cancelled',
                'group' => '1',
                'call_id' => $call->uuid,
                'reason' => (string) ($call->end_reason ?? 'answered'),
            ]);
        }
    }

    protected function sendPush(User $user, array $data): void
    {
        try {
            $message = CloudMessage::withTarget('token', $user->fcm_token)
                ->withData($data)
                ->withAndroidConfig(AndroidConfig::fromArray([
                    'priority' => 'high',
                    'ttl' => '45s',
                ]));

            Firebase::messaging()->send($message);
        } catch (NotFound $e) {
            // Dead token (app reinstalled): clear it.
            $user->update(['fcm_token' => null]);
        } catch (\Throwable $e) {
            Log::warning('Group call push failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}