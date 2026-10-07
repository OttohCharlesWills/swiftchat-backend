<?php

namespace App\Http\Controllers\Api;

use App\Events\CallSignal;
use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\Contact;
use App\Models\User;
use App\Services\IceService;
use App\Services\LiveKitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Laravel\Firebase\Facades\Firebase;

class CallController extends Controller
{
    public function __construct(
        protected LiveKitService $livekit,
        protected IceService $ice,
    ) {
    }

    // ============================================================
    // HISTORY
    // ============================================================

    /** GET /calls */
    public function index(Request $request)
    {
        $user = $request->user();

        $calls = Call::with(['caller', 'callee'])
            ->where(function ($w) use ($user) {
                $w->where('caller_id', $user->id)->orWhere('callee_id', $user->id);
            })
            ->whereNotIn('status', ['ringing'])
            ->latest()
            ->paginate(30);

        return response()->json($calls->through(fn ($c) => $c->toApi($user)));
    }

    /** GET /calls/{call} */
    public function show(Request $request, Call $call)
    {
        $this->authorizeParticipant($request, $call);

        $call->load(['caller', 'callee']);

        return response()->json(['call' => $call->toApi($request->user())]);
    }

    // ============================================================
    // START
    // ============================================================

    /** POST /calls  { callee_id, chat_id? } */
    public function start(Request $request)
    {
        $data = $request->validate([
            'callee_id' => 'required|integer|exists:users,id',
            'chat_id' => 'nullable|integer|exists:chats,id',
        ]);

        $caller = $request->user();
        $calleeId = (int) $data['callee_id'];

        abort_if($calleeId === (int) $caller->id, 422, "You can't call yourself.");

        Call::expireStale();

        // The other person blocked you.
        $blocked = DB::table('contacts')
            ->where('user_id', $calleeId)
            ->where('contact_user_id', $caller->id)
            ->where('is_blocked', true)
            ->exists();

        abort_if($blocked, 403, 'You cannot call this person.');

        if (Call::activeFor((int) $caller->id)->exists()) {
            return response()->json(['message' => 'You are already in a call.'], 409);
        }

        $busy = Call::activeFor($calleeId)->exists();

        $call = Call::create([
            'caller_id' => $caller->id,
            'callee_id' => $calleeId,
            'chat_id' => $data['chat_id'] ?? null,
            'type' => 'voice',
            'status' => $busy ? 'busy' : 'ringing',
            'ended_at' => $busy ? now() : null,
            'end_reason' => $busy ? 'busy' : null,
        ]);

        $call->load(['caller', 'callee']);

        // They're on another call: tell the caller straight away.
        if ($busy) {
            return response()->json(['call' => $call->toApi($caller), 'busy' => true]);
        }

        // Ring the other phone: realtime if the app is open, push if not.
        $this->signal($call, 'call.incoming', [$calleeId]);
        $this->pushIncoming($call);

        return response()->json(array_merge(
            ['call' => $call->toApi($caller)],
            $this->mediaFor($call, $caller)
        ), 201);
    }

    // ============================================================
    // ANSWER / DECLINE
    // ============================================================

    /** POST /calls/{call}/accept */
    public function accept(Request $request, Call $call)
    {
        $user = $request->user();

        abort_unless((int) $call->callee_id === (int) $user->id, 403, 'This call is not for you.');

        Call::expireStale();

        $updated = $this->transition($call, ['ringing'], 'accepted', ['answered_at' => now()]);

        if (!$updated) {
            $call->refresh()->load(['caller', 'callee']);

            return response()->json([
                'message' => 'This call is no longer available.',
                'call' => $call->toApi($user),
            ], 409);
        }

        // Tells the caller to connect, and stops ringing on your other devices.
        $this->signal($updated, 'call.updated', [$updated->caller_id, $updated->callee_id]);

        return response()->json(array_merge(
            ['call' => $updated->toApi($user)],
            $this->mediaFor($updated, $user)
        ));
    }

    /** POST /calls/{call}/decline */
    public function decline(Request $request, Call $call)
    {
        $user = $request->user();

        abort_unless((int) $call->callee_id === (int) $user->id, 403, 'This call is not for you.');

        $updated = $this->transition($call, ['ringing'], 'declined', [
            'ended_at' => now(),
            'ended_by' => $user->id,
            'end_reason' => 'declined',
        ]);

        return $this->finish($request, $call, $updated);
    }

    // ============================================================
    // CANCEL (caller hangs up while it is still ringing)
    // ============================================================

    /** POST /calls/{call}/cancel   { timeout?: bool } */
    public function cancel(Request $request, Call $call)
    {
        $user = $request->user();

        abort_unless((int) $call->caller_id === (int) $user->id, 403, 'Not your call.');

        $timedOut = $request->boolean('timeout');

        $updated = $this->transition($call, ['ringing'], $timedOut ? 'missed' : 'cancelled', [
            'ended_at' => now(),
            'ended_by' => $user->id,
            'end_reason' => $timedOut ? 'timeout' : 'cancelled',
        ]);

        if ($updated) {
            // Makes the other phone stop ringing even if the app is closed.
            $this->pushCancelled($updated);
        }

        return $this->finish($request, $call, $updated);
    }

    // ============================================================
    // END (hang up during a call)
    // ============================================================

    /** POST /calls/{call}/end */
    public function end(Request $request, Call $call)
    {
        $user = $request->user();

        $this->authorizeParticipant($request, $call);

        // Hanging up before they answered = cancelling.
        if ($call->status === 'ringing' && (int) $call->caller_id === (int) $user->id) {
            return $this->cancel($request, $call);
        }

        $seconds = $call->answered_at
            ? (int) abs(now()->diffInSeconds($call->answered_at))
            : 0;

        $updated = $this->transition($call, ['accepted'], 'ended', [
            'ended_at' => now(),
            'ended_by' => $user->id,
            'duration_seconds' => $seconds,
            'end_reason' => 'hangup',
        ]);

        return $this->finish($request, $call, $updated);
    }

    // ============================================================
    // NEW TOKEN (e.g. after a reconnect)
    // ============================================================

    /** POST /calls/{call}/token */
    public function token(Request $request, Call $call)
    {
        $user = $request->user();

        $this->authorizeParticipant($request, $call);

        $allowed = $call->status === 'accepted'
            || ($call->status === 'ringing' && (int) $call->caller_id === (int) $user->id);

        abort_unless($allowed, 409, 'This call is over.');

        return response()->json($this->mediaFor($call, $user));
    }

    // ============================================================
    // WEBRTC HANDSHAKE (peer-to-peer mode)
    // ============================================================

    /**
     * POST /calls/{call}/signal   { kind: offer|answer|candidate, data: {...} }
     *
     * The two phones find each other by passing small messages (offer,
     * answer, network candidates) through the server. The audio itself never
     * touches your server: it goes phone to phone (or through your TURN).
     */
    public function relay(Request $request, Call $call)
    {
        $user = $request->user();

        $this->authorizeParticipant($request, $call);

        $data = $request->validate([
            'kind' => 'required|in:offer,answer,candidate',
            'data' => 'required|array',
        ]);

        abort_unless($call->status === 'accepted', 409, 'The call is not connected.');
        abort_if(strlen((string) json_encode($data['data'])) > 8000, 422, 'Signal too large.');

        $otherId = (int) $user->id === (int) $call->caller_id
            ? (int) $call->callee_id
            : (int) $call->caller_id;

        try {
            broadcast(new CallSignal($otherId, 'call.signal', [
                'call_id' => $call->uuid,
                'from' => (int) $user->id,
                'kind' => $data['kind'],
                'data' => $data['data'],
            ]));
        } catch (\Throwable $e) {
            Log::warning('Call relay failed', ['call' => $call->uuid, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Could not reach the other phone.'], 503);
        }

        return response()->json(['ok' => true]);
    }

    // ============================================================
    // HELPERS
    // ============================================================

    /**
     * Changes the status only if the call is still in one of the $from
     * states. Row-locked, so "accept" and "cancel" at the same instant
     * can't both win.
     */
    protected function transition(Call $call, array $from, string $to, array $extra = []): ?Call
    {
        return DB::transaction(function () use ($call, $from, $to, $extra) {
            $fresh = Call::whereKey($call->id)->lockForUpdate()->first();

            if (!$fresh || !in_array($fresh->status, $from, true)) {
                return null;
            }

            $fresh->fill(array_merge(['status' => $to], $extra))->save();

            return $fresh->load(['caller', 'callee']);
        });
    }

    /** Shared ending for decline / cancel / end: tell both phones, answer the caller of the API. */
    protected function finish(Request $request, Call $call, ?Call $updated)
    {
        $user = $request->user();

        if ($updated) {
            $this->signal($updated, 'call.updated', [$updated->caller_id, $updated->callee_id]);

            return response()->json(['call' => $updated->toApi($user)]);
        }

        // Already over (the other person got there first): just report it.
        $call->refresh()->load(['caller', 'callee']);

        return response()->json(['call' => $call->toApi($user)]);
    }

    /**
     * What the phone needs to connect the audio:
     *  - p2p:     the ICE servers (free STUN + your TURN)
     *  - livekit: a LiveKit url + token
     */
    protected function mediaFor(Call $call, User $user): array
    {
        if (config('calls.mode', 'p2p') === 'livekit') {
            abort_unless($this->livekit->isConfigured(), 500, 'Calls are not set up on the server yet.');

            return [
                'mode' => 'livekit',
                'livekit' => [
                    'url' => $this->livekit->url(),
                    'token' => $this->livekit->token('u' . $user->id, (string) $user->name, $call->room_name),
                ],
            ];
        }

        return [
            'mode' => 'p2p',
            'ice_servers' => $this->ice->servers((int) $user->id),
        ];
    }

    protected function authorizeParticipant(Request $request, Call $call): void
    {
        $id = (int) $request->user()->id;

        abort_unless(
            $id === (int) $call->caller_id || $id === (int) $call->callee_id,
            403,
            'You are not part of this call.'
        );
    }

    /** Realtime message to people whose app is open (Pusher). */
    protected function signal(Call $call, string $event, array $userIds): void
    {
        foreach (array_unique(array_map('intval', $userIds)) as $id) {
            $viewer = $id === (int) $call->caller_id ? $call->caller : $call->callee;

            try {
                broadcast(new CallSignal($id, $event, ['call' => $viewer ? $call->toApi($viewer) : []]));
            } catch (\Throwable $e) {
                Log::warning('Call signal failed', [
                    'call' => $call->uuid,
                    'user_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * High-priority data push so the phone can ring even when the app is
     * closed. (The app turns it into a full-screen incoming-call screen.)
     */
    protected function pushIncoming(Call $call): void
    {
        $caller = $call->caller;
        $callee = $call->callee;

        if (!$callee || empty($callee->fcm_token)) {
            Log::info('Call push skipped: callee has no fcm_token', ['user_id' => $callee?->id]);
            return;
        }

        $callerName = Contact::where('user_id', $callee->id)
            ->where('contact_user_id', $caller->id)
            ->value('saved_name') ?: $caller->name;

        $this->sendPush($callee, [
            'type' => 'incoming_call',
            'call_id' => $call->uuid,
            'caller_id' => (string) $caller->id,
            'caller_name' => (string) $callerName,
            'caller_avatar' => (string) ($caller->avatar_url ?? ''),
            'chat_id' => (string) ($call->chat_id ?? ''),
            'ring_seconds' => (string) config('calls.ring_seconds', 45),
        ]);
    }

    protected function pushCancelled(Call $call): void
    {
        $callee = $call->callee;

        if (!$callee || empty($callee->fcm_token)) {
            return;
        }

        $this->sendPush($callee, [
            'type' => 'call_cancelled',
            'call_id' => $call->uuid,
            'reason' => (string) $call->end_reason,
        ]);
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
            Log::warning('Call push failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
