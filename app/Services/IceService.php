<?php

namespace App\Services;

/**
 * Builds the "ICE servers" list the phones need for a direct (peer-to-peer)
 * call: free STUN servers, plus your own TURN server if you have one.
 */
class IceService
{
    public function servers(int $userId): array
    {
        $servers = [
            ['urls' => array_values((array) config('calls.ice.stun'))],
        ];

        $turnUrls = (array) config('calls.ice.turn_urls');

        if (!$turnUrls) {
            return $servers;
        }

        $secret = config('calls.ice.turn_secret');

        if ($secret) {
            // coturn REST-style credentials: username = "expiry:name",
            // password = base64(hmac-sha1(secret, username)). Works until the
            // expiry time, then stops working on its own.
            $username = (time() + (int) config('calls.ice.turn_ttl', 86400)) . ':u' . $userId;
            $credential = base64_encode(hash_hmac('sha1', $username, (string) $secret, true));
        } elseif (config('calls.ice.turn_username') && config('calls.ice.turn_credential')) {
            $username = (string) config('calls.ice.turn_username');
            $credential = (string) config('calls.ice.turn_credential');
        } else {
            return $servers;
        }

        $servers[] = [
            'urls' => array_values($turnUrls),
            'username' => $username,
            'credential' => $credential,
        ];

        return $servers;
    }
}
