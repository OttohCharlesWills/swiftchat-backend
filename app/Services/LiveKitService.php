<?php

namespace App\Services;

use RuntimeException;

/**
 * Makes LiveKit access tokens. A token is a signed JWT (HS256) that lets one
 * person join one room. No extra composer package needed.
 */
class LiveKitService
{
    public function isConfigured(): bool
    {
        return config('calls.livekit.url')
            && config('calls.livekit.key')
            && config('calls.livekit.secret');
    }

    public function url(): string
    {
        return (string) config('calls.livekit.url');
    }

    public function token(string $identity, string $name, string $room, ?int $ttl = null): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('LiveKit is not configured.');
        }

        $key = (string) config('calls.livekit.key');
        $secret = (string) config('calls.livekit.secret');
        $ttl = $ttl ?? (int) config('calls.token_ttl', 7200);
        $now = time();

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];

        $payload = [
            'iss' => $key,
            'sub' => $identity,
            'name' => $name,
            'nbf' => $now - 10,
            'exp' => $now + $ttl,
            'video' => [
                'room' => $room,
                'roomJoin' => true,
                'canPublish' => true,
                'canSubscribe' => true,
                'canPublishData' => true,
            ],
        ];

        $segments = [
            $this->base64Url(json_encode($header)),
            $this->base64Url(json_encode($payload)),
        ];

        $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
        $segments[] = $this->base64Url($signature);

        return implode('.', $segments);
    }

    protected function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
