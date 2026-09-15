<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailVerificationService
{
    public function sendCode(string $email, string $code): bool
    {
        try {
            Mail::raw(
                "Your SwiftChat verification code is: {$code}. It expires in 10 minutes.",
                function ($message) use ($email) {
                    $message
                        ->to($email)
                        ->subject('SwiftChat Email Verification');
                }
            );

            return true;
        } catch (\Exception $e) {
            Log::error('SwiftChat email verification failed: ' . $e->getMessage());

            return false;
        }
    }
}