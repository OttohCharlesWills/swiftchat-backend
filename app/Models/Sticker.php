<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Sticker extends Model
{
    protected $fillable = [
        'sticker_pack_id', 'file_path', 'format', 'is_animated', 'width', 'height',
        'file_size', 'checksum', 'emoji', 'keywords', 'position',
    ];

    protected $casts = [
        'is_animated' => 'boolean',
        'keywords' => 'array',
    ];

    // When a sticker is nested in a message's JSON the app gets a ready-to-load "url"
    protected $appends = ['url'];

    protected $hidden = ['file_path', 'checksum', 'file_size', 'created_at', 'updated_at'];

    protected static function booted(): void
    {
        static::creating(function (self $sticker) {
            if (empty($sticker->uuid)) {
                $sticker->uuid = (string) Str::uuid();
            }
        });

        static::deleting(function (self $sticker) {
            Storage::disk(config('stickers.disk'))->delete($sticker->file_path);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function pack()
    {
        return $this->belongsTo(StickerPack::class, 'sticker_pack_id');
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk(config('stickers.disk'))->url($this->file_path);
    }

    /**
     * Bump this sticker in the user's "recent" list. Call it when a sticker message is sent.
     */
    public function recordUseBy(int $userId): void
    {
        $now = now();

        DB::table('user_recent_stickers')->upsert(
            [[
                'user_id' => $userId,
                'sticker_id' => $this->id,
                'use_count' => 1,
                'last_used_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['user_id', 'sticker_id'],
            ['use_count' => DB::raw('use_count + 1'), 'last_used_at' => $now, 'updated_at' => $now]
        );
    }
}