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

    // Step 1: mobile app generates the link + code
    public function generate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'duration' => 'required|in:1hr,3hr,6hr,1day',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $hours = $this->durationMap[$request->duration];

        $session = DesktopSession::create([
            'user_id'    => $request->user()->id,
            'token'      => Str::random(48),
            'code'       => (string) random_int(100000, 999999),
            'duration'   => $request->duration,
            'expires_at' => now()->addHours($hours),
            'status'     => 'pending',
        ]);

        return response()->json([
            'message'    => 'Desktop session generated.',
            'link'       => "https://gist.app/desktop?token={$session->token}",
            'code'       => $session->code, // shown ONLY here, on the mobile screen
            'expires_at' => $session->expires_at,
        ], 201);
    }

    // Step 2: desktop page checks if the token is valid before showing the code screen
    public function check(Request $request, string $token)
    {
        $session = DesktopSession::where('token', $token)->first();

        if (!$session) {
            return response()->json(['message' => 'Invalid link.'], 404);
        }

        if ($session->status === 'revoked') {
            return response()->json(['message' => 'This link has been revoked.'], 410);
        }

        if ($session->isExpired()) {
            $session->update(['status' => 'expired']);
            return response()->json(['message' => 'This link has expired.'], 410);
        }

        if ($session->status === 'active') {
            return response()->json(['message' => 'This session was already used.'], 410);
        }

        return response()->json(['message' => 'Link valid. Enter the code shown on your phone.']);
    }

    // Step 3: desktop submits the code entered by the user
    public function verify(Request $request, string $token)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $session = DesktopSession::where('token', $token)->first();

        if (!$session) {
            return response()->json(['message' => 'Invalid link.'], 404);
        }

        if ($session->isExpired()) {
            $session->update(['status' => 'expired']);
            return response()->json(['message' => 'This link has expired.'], 410);
        }

        if ($session->status !== 'pending') {
            return response()->json(['message' => 'This session is no longer available.'], 410);
        }

        if ($session->attempts >= 5) {
            $session->update(['status' => 'revoked']);
            return response()->json(['message' => 'Too many failed attempts. Link revoked.'], 429);
        }

        if ($session->code !== $request->code) {
            $session->increment('attempts');
            return response()->json([
                'message'          => 'Incorrect code.',
                'attempts_left'    => 5 - $session->attempts,
            ], 400);
        }

        // Code matched — activate session
        $session->update([
            'status'      => 'active',
            'verified_at' => now(),
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        // Issue a token scoped for desktop use, tied to the same user account
        $authToken = $session->user->createToken('desktop_session_' . $session->id)->plainTextToken;

        return response()->json([
            'message' => 'Desktop session activated.',
            'user'    => $session->user,
            'token'   => $authToken,
        ]);
    }

    // Optional: user can view/revoke active desktop sessions from mobile
    public function index(Request $request)
    {
        $sessions = DesktopSession::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($sessions);
    }

    public function revoke(Request $request, $id)
    {
        $session = DesktopSession::where('user_id', $request->user()->id)->findOrFail($id);
        $session->update(['status' => 'revoked']);

        return response()->json(['message' => 'Session revoked.']);
    }
}