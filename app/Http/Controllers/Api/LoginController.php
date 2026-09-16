<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LoginController extends Controller
{
    protected EmailVerificationService $emailService;

    public function __construct(EmailVerificationService $emailService)
    {
        $this->emailService = $emailService;
    }

    // Step 1: request OTP for login
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => ['required', 'string', 'exists:users,phone_number', 'regex:/^\+[1-9]\d{6,14}$/'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('phone_number', $request->phone_number)->first();

        // A user can only reach the login OTP step if they have an email
        // on file (set during the original email-verification step). If
        // that's somehow missing, fail loudly instead of silently trying
        // to email a null address.
        if (!$user->email) {
            return response()->json([
                'message' => 'No email on file for this account. Please contact support.',
            ], 400);
        }

        $otp = random_int(100000, 999999);

        $user->update([
            'otp_code'       => $otp,
            'otp_expires_at' => now()->addMinutes(5),
        ]);

        $sent = $this->emailService->sendCode($user->email, (string) $otp);

        if (!$sent) {
            // Remove the code if email delivery failed, same pattern as
            // RegisterController::requestVerificationCode().
            $user->update([
                'otp_code'       => null,
                'otp_expires_at' => null,
            ]);

            return response()->json([
                'message' => 'Failed to send OTP. Please try again.',
            ], 500);
        }

        return response()->json(['message' => 'OTP sent to your email.']);
    }

    // Step 2: verify OTP and issue token
    public function verifyLoginOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|exists:users,phone_number',
            'otp_code'     => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('phone_number', $request->phone_number)->first();

        if (!$user->otp_code || $user->otp_code !== $request->otp_code) {
            return response()->json(['message' => 'Invalid OTP.'], 400);
        }

        if (now()->greaterThan($user->otp_expires_at)) {
            return response()->json(['message' => 'OTP expired.'], 400);
        }

        // FLAGGING A CHANGE: the original code set 'is_verified' => true
        // here unconditionally. That let an account log in via this SMS/
        // email OTP path and get marked verified without ever completing
        // the actual email-verification step in RegisterController. Since
        // your whole app-lock feature depends on is_verified meaning what
        // it says, I removed that line — this endpoint now only clears
        // the OTP and issues a token, it doesn't touch is_verified at all.
        $user->update([
            'otp_code'       => null,
            'otp_expires_at' => null,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'user'    => $user,
            'token'   => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}