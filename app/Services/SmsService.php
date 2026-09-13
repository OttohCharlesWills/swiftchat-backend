<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    protected string $apiKey;
    protected string $senderId;

    public function __construct()
    {
        $this->apiKey = config('services.termii.api_key');
        $this->senderId = config('services.termii.sender_id');
    }

    public function sendOtp(string $toNumber, string $otp): bool
    {
        try {
            $response = Http::post('https://api.ng.termii.com/api/sms/send', [
                'to'      => $toNumber,
                'from'    => $this->senderId,
                'sms'     => "Your Swift Chat verification code is: {$otp}. It expires in 5 minutes.",
                'type'    => 'plain',
                'channel' => 'generic',
                'api_key' => $this->apiKey,
            ]);

            if ($response->successful() && ($response->json('code') === 'ok')) {
                return true;
            }

            Log::error('Termii SMS failed: ' . $response->body());
            return false;
        } catch (\Exception $e) {
            Log::error('Termii SMS exception: ' . $e->getMessage());
            return false;
        }
    }
}