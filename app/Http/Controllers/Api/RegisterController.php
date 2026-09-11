<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RegisterController extends Controller
{
    protected SmsService $smsService;

    public function __construct(SmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => ['required', 'string', 'unique:users,phone_number', 'regex:/^\+[1-9]\d{6,14}$/'],
            'name'         => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $otp = random_int(100000, 999999);

        $user = User::create([
            'phone_number'   => $request->phone_number,
            'name'           => $request->name,
            'otp_code'       => $otp,
            'otp_expires_at' => now()->addMinutes(5),
        ]);

        $sent = $this->smsService->sendOtp($user->phone_number, (string) $otp);

        if (!$sent) {
            return response()->json([
                'message'      => 'Account created, but SMS failed to send. Try resending OTP.',
                'phone_number' => $user->phone_number,
            ], 201);
        }

        return response()->json([
            'message'      => 'Account created. OTP sent via SMS.',
            'phone_number' => $user->phone_number,
        ], 201);
    }

    public function verifyOtp(Request $request)
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

        $user->update([
            'is_verified'    => true,
            'otp_code'       => null,
            'otp_expires_at' => null,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Account verified.',
            'user'    => $user,
            'token'   => $token,
        ]);
    }
}