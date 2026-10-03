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

    'recent_limit' => 100,

    // How many stickers each user can keep in their own "My Stickers" pack
    'user_sticker_limit' => 100,

    // Collections (saved stickers): stickers per collection, and collections per user.
    // Favorites have no limit.
    'collection_sticker_limit' => 100,
    'max_collections' => 80,

    // Secret for the admin import endpoint (X-Sticker-Admin-Key header)
    'admin_key' => env('STICKERS_ADMIN_KEY'),
];