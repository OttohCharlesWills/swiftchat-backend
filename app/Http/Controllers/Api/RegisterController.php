<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RegisterController extends Controller
{
    protected EmailVerificationService $emailService;

    public function __construct(EmailVerificationService $emailService)
    {
        $this->emailService = $emailService;
    }

    /**
     * Create a new account.
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => [
                'required',
                'string',
                'unique:users,phone_number',
                'regex:/^\+[1-9]\d{6,14}$/',
            ],
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::create([
            'phone_number' => $request->phone_number,
            'name' => $request->name,
            'is_verified' => false,
        ]);

        // Issue a token right away so the app can authenticate immediately
        // (e.g. to call /me) even before the account is verified. Actions
        // that require verification stay gated by the `verified.user`
        // middleware on the routes that need it — this token alone doesn't
        // unlock anything beyond "who am I".
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Account created. Please verify your account.',
            'user' => $user,
            'token' => $token,
            'is_verified' => false,
        ], 201);
    }

    /**
     * Send verification code to user's email.
     */
    public function requestVerificationCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|exists:users,phone_number',
            'email' => 'required|email|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where(
            'phone_number',
            $request->phone_number
        )->first();

        if ($user->is_verified) {
            return response()->json([
                'message' => 'Account is already verified.',
            ], 400);
        }

        $code = random_int(100000, 999999);

        $user->update([
            'email' => $request->email,
            'otp_code' => $code,
            'otp_expires_at' => now()->addMinutes(10),
        ]);

        $sent = $this->emailService->sendCode(
            $user->email,
            (string) $code
        );

        if (!$sent) {
            $user->update([
                'otp_code' => null,
                'otp_expires_at' => null,
            ]);

            return response()->json([
                'message' => 'Unable to send verification email. Please try again.',
            ], 500);
        }

        return response()->json([
            'message' => 'Verification code sent to your email.',
            'otp_expires_at' => $user->otp_expires_at,
        ], 200);
    }

    /**
     * Verify the account using the email code.
     */
    public function verifyAccount(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|exists:users,phone_number',
            'code' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where(
            'phone_number',
            $request->phone_number
        )->first();

        if ($user->is_verified) {
            return response()->json([
                'message' => 'Account is already verified.',
            ], 400);
        }

        if (!$user->otp_code || !$user->otp_expires_at) {
            return response()->json([
                'message' => 'Please request a verification code first.',
            ], 400);
        }

        if (now()->greaterThan($user->otp_expires_at)) {
            return response()->json([
                'message' => 'Verification code expired.',
            ], 400);
        }

        if ((string) $user->otp_code !== (string) $request->code) {
            return response()->json([
                'message' => 'Invalid verification code.',
            ], 400);
        }

        // Set is_verified directly on the model rather than through
        // update()'s mass assignment. update() silently drops any column
        // not listed in the model's $fillable array with no error at
        // all — that's almost certainly why this was never actually
        // persisting: otp_code/otp_expires_at were fillable and cleared
        // fine, but is_verified quietly never made it through.
        $user->is_verified = true;
        $user->otp_code = null;
        $user->otp_expires_at = null;
        $user->save();

        // A token already exists from registration in most cases, but issue
        // a fresh one here too (e.g. covers verifying from a different
        // device/session than the one that registered).
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Account verified successfully.',
            'user' => $user,
            'token' => $token,
        ], 200);
    }
}