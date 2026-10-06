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
        'file_size', 'checksum', 'emoji', 'keywords', 'position', 'is_removed', 

    ];

    protected $casts = [
        'is_animated' => 'boolean',
        'keywords' => 'array',
        'is_removed' => 'boolean',
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
     * Stickers this user is allowed to send: public stickers, their own, and
     * anything they saved to favorites or a collection (even a sticker a friend
     * made privately and sent them).
     */
    public function scopeUsableBy($query, int $userId)
    {
        $favorites = DB::table('user_favorite_stickers')
            ->where('user_id', $userId)
            ->select('sticker_id');

        $collected = DB::table('sticker_collection_items as i')
            ->join('sticker_collections as c', 'c.id', '=', 'i.collection_id')
            ->where('c.user_id', $userId)
            ->select('i.sticker_id');

        return $query->where(function ($q) use ($userId, $favorites, $collected) {
            $q->where(function ($own) use ($userId) {
                $own->where('stickers.is_removed', false)
                    ->whereHas('pack', fn ($p) => $p->active()->visibleTo($userId));
            })
                ->orWhereIn('stickers.id', $favorites)
                ->orWhereIn('stickers.id', $collected);
        });
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_removed', false);
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