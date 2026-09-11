<?php

namespace App\Services;

use Twilio\Rest\Client;
use Illuminate\Support\Facades\Log;

class SmsService
{
    protected Client $client;
    protected string $fromNumber;

    public function __construct()
    {
        // When using API Key + Secret (not Auth Token), the Account SID
        // must be passed as the third argument.
        $this->client = new Client(
            config('services.twilio.api_key'),
            config('services.twilio.api_secret'),
            config('services.twilio.account_sid')
        );

        $this->fromNumber = config('services.twilio.from_number');
    }

    public function sendOtp(string $toNumber, string $otp): bool
    {
        try {
            $this->client->messages->create($toNumber, [
                'from' => $this->fromNumber,
                'body' => "Your Gist verification code is: {$otp}. It expires in 5 minutes.",
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Twilio SMS failed: ' . $e->getMessage());
            return false;
        }
    }
}