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

        return response()->json([
            'message' => 'Account created. Please verify your account.',
            'phone_number' => $user->phone_number,
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

        // Save email and verification code.
        $user->update([
            'email' => $request->email,
            'otp_code' => $code,
            'otp_expires_at' => now()->addMinutes(10),
        ]);

        // Send code to email.
        $sent = $this->emailService->sendCode(
            $user->email,
            (string) $code
        );

        if (!$sent) {
            // Remove the code if email delivery failed.
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

        // No code has been requested.
        if (!$user->otp_code || !$user->otp_expires_at) {
            return response()->json([
                'message' => 'Please request a verification code first.',
            ], 400);
        }

        // Code has expired.
        if (now()->greaterThan($user->otp_expires_at)) {
            return response()->json([
                'message' => 'Verification code expired.',
            ], 400);
        }

        // Code is incorrect.
        if ((string) $user->otp_code !== (string) $request->code) {
            return response()->json([
                'message' => 'Invalid verification code.',
            ], 400);
        }

        // Account successfully verified.
        $user->update([
            'is_verified' => true,
            'otp_code' => null,
            'otp_expires_at' => null,
        ]);

        // Create authentication token.
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Account verified successfully.',
            'user' => $user,
            'token' => $token,
        ], 200);
    }
}