<?php

return [
    // Any filesystem disk from config/filesystems.php (public, s3, supabase-s3, ...)
    'disk' => env('STICKERS_DISK', 'public'),

    'directory' => 'stickers',

    // Per-file limit in bytes (WhatsApp: 100KB static / 500KB animated)
    'max_file_size' => 1024 * 1024,

    'allowed_extensions' => ['webp', 'png', 'gif', 'jpg', 'jpeg', 'json'],

    // Archive safety limits
    'max_archive_files' => 500,
    'max_archive_bytes' => 50 * 1024 * 1024,

    'recent_limit' => 30,

    // Secret for the admin import endpoint (X-Sticker-Admin-Key header)
    'admin_key' => env('STICKERS_ADMIN_KEY'),
];