<?php

return [
    // p2p      = free: the two phones talk directly (WebRTC); your server only
    //            helps them find each other. Needs a TURN server for the calls
    //            that can't connect directly (see coturn-setup.md).
    // livekit  = managed service (LiveKit Cloud / self-hosted LiveKit).
    'mode' => env('CALLS_MODE', 'p2p'),

    // How long a call rings before it counts as missed.
    'ring_seconds' => 45,

    // ---------------- p2p ----------------
    'ice' => [
        // Free public STUN servers (they only help phones discover their address).
        'stun' => ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302'],

        // YOUR TURN server (relays audio when a direct connection fails).
        // Comma separated, e.g.
        //   turn:turn.example.com:3478?transport=udp,turn:turn.example.com:3478?transport=tcp
        'turn_urls' => array_values(array_filter(array_map('trim', explode(',', (string) env('TURN_URLS', ''))))),

        // Option A (recommended, coturn "use-auth-secret"): short-lived
        // passwords are made for each call from this one secret.
        'turn_secret' => env('TURN_SECRET'),
        'turn_ttl' => 86400,

        // Option B: one fixed username/password (e.g. from a hosted TURN provider).
        'turn_username' => env('TURN_USERNAME'),
        'turn_credential' => env('TURN_CREDENTIAL'),
    ],

    // ---------------- livekit (only if CALLS_MODE=livekit) ----------------
    'livekit' => [
        'url' => env('LIVEKIT_URL'),
        'key' => env('LIVEKIT_API_KEY'),
        'secret' => env('LIVEKIT_API_SECRET'),
    ],
    'token_ttl' => 7200,
];
