<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DesktopSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class DesktopSessionController extends Controller
{
    private array $durationMap = [
        '1hr'  => 1,
        '3hr'  => 3,
        '6hr'  => 6,
        '1day' => 24,
    ];

    /**
     * Step 1:
     * Mobile app generates a desktop access link + verification code.
     */
    public function generate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'duration' => 'required|in:1hr,3hr,6hr,1day',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $hours = $this->durationMap[$request->duration];

        $session = DesktopSession::create([
            'user_id'    => $request->user()->id,
            'token'      => Str::random(64),
            'code'       => (string) random_int(100000, 999999),
            'duration'   => $request->duration,
            'expires_at' => now()->addHours($hours),
            'status'     => 'pending',
        ]);

        return response()->json([
            'message'    => 'Desktop session generated.',
            'link'       => "https://coremonitor.in/desktop?token={$session->token}",
            'code'       => $session->code,
            'expires_at' => $session->expires_at,
            'duration'   => $session->duration,
        ], 201);
    }

    /**
     * Step 2:
     * Desktop checks whether the access link is still valid.
     */
    public function check(Request $request, string $token)
    {
        $session = DesktopSession::where('token', $token)->first();

        if (!$session) {
            return response()->json([
                'message' => 'Invalid link.',
            ], 404);
        }

        if ($session->status === 'revoked') {
            return response()->json([
                'message' => 'This link has been revoked.',
            ], 410);
        }

        if ($session->status === 'expired' || $session->isExpired()) {
            $session->expire();

            return response()->json([
                'message' => 'This link has expired.',
            ], 410);
        }

        if ($session->status === 'active') {
            return response()->json([
                'message' => 'This session was already used.',
            ], 410);
        }

        return response()->json([
            'message' => 'Link valid. Enter the code shown on your phone.',
            'status'  => 'pending',
            'expires_at' => $session->expires_at,
        ]);
    }

    /**
     * Step 3:
     * Desktop submits the 6-digit code shown on the mobile device.
     */
    public function verify(Request $request, string $token)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|size:6|regex:/^[0-9]{6}$/',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $session = DesktopSession::where('token', $token)->first();

        if (!$session) {
            return response()->json([
                'message' => 'Invalid link.',
            ], 404);
        }

        /*
         * Never allow a session to remain usable after its expiry time.
         */
        if ($session->isExpired()) {
            $session->expire();

            return response()->json([
                'message' => 'This link has expired.',
            ], 410);
        }

        if ($session->status === 'revoked') {
            return response()->json([
                'message' => 'This link has been revoked.',
            ], 410);
        }

        if ($session->status === 'active') {
            return response()->json([
                'message' => 'This session has already been activated.',
            ], 410);
        }

        if ($session->status !== 'pending') {
            return response()->json([
                'message' => 'This session is no longer available.',
            ], 410);
        }

        /*
         * Maximum of 5 failed attempts.
         */
        if ($session->attempts >= 5) {
            $session->revoke();

            return response()->json([
                'message' => 'Too many failed attempts. Link revoked.',
            ], 429);
        }

        /*
         * Wrong code.
         */
        if (!hash_equals((string) $session->code, (string) $request->code)) {
            $session->increment('attempts');

            $attemptsLeft = max(0, 5 - ($session->attempts + 1));

            /*
             * The fifth wrong attempt immediately kills the session.
             */
            if ($session->attempts >= 5) {
                $session->revoke();

                return response()->json([
                    'message'       => 'Too many failed attempts. Link revoked.',
                    'attempts_left' => 0,
                ], 429);
            }

            return response()->json([
                'message'       => 'Incorrect code.',
                'attempts_left' => $attemptsLeft,
            ], 400);
        }

        /*
         * Code matched.
         *
         * The desktop session itself becomes active.
         */
        $session->update([
            'status'      => 'active',
            'verified_at' => now(),
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        /*
         * Create a Sanctum token that expires at EXACTLY the same
         * time as the DesktopSession.
         *
         * The "desktop_session:{id}" ability lets us identify this
         * token as belonging to this specific desktop session.
         */
        $accessToken = $session->user->createToken(
            'desktop_session_' . $session->id,
            ['desktop_session:' . $session->id],
            $session->expires_at
        );

        /*
         * Store the Sanctum token ID on the desktop session.
         *
         * This requires a desktop_token_id column in the table.
         */
        $session->update([
            'desktop_token_id' => $accessToken->accessToken->id,
        ]);

        return response()->json([
            'message' => 'Desktop session activated.',
            'user' => $session->user,
            'token' => $accessToken->plainTextToken,
            'expires_at' => $session->expires_at,
        ]);
    }

    /**
     * Mobile:
     * List all desktop sessions belonging to the current user.
     */
    public function index(Request $request)
    {
        $sessions = DesktopSession::where(
            'user_id',
            $request->user()->id
        )
        ->orderByDesc('created_at')
        ->get();

        /*
        * Automatically clean up sessions that have passed
        * their expiry time.
        */
        foreach ($sessions as $session) {
            if (
                in_array($session->status, ['pending', 'active'], true)
                && $session->isExpired()
            ) {
                $session->expire();
            }
        }

        /*
        * Reload after status updates.
        */
        $sessions->each(function ($session) {
            $session->refresh();
        });

        /*
        * token and code are hidden on the model, so toArray() drops them.
        * Add them back only for this user's still-valid pending sessions,
        * so the phone can show the link and code again.
        */
        $payload = $sessions->map(function ($session) {
            $data = $session->toArray();

            if ($session->status === 'pending' && !$session->isExpired()) {
                $data['code'] = $session->code;
                $data['link'] = url('/desktop?token=' . $session->token);
            }

            return $data;
        })->values();

        return response()->json([
            'sessions' => $payload,
        ]);
    }

    /**
     * Mobile:
     * Revoke a specific desktop session.
     */
    public function revoke(Request $request, int $id)
    {
        $session = DesktopSession::where(
            'user_id',
            $request->user()->id
        )->find($id);

        if (!$session) {
            return response()->json([
                'message' => 'Desktop session not found.',
            ], 404);
        }

        if ($session->status === 'revoked') {
            return response()->json([
                'message' => 'Desktop session is already revoked.',
            ]);
        }

        $session->revoke();

        return response()->json([
            'message' => 'Desktop session revoked.',
        ]);
    }
}
