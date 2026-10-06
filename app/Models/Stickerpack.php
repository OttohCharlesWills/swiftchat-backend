<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class StickerPack extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'author', 'description', 'cover_sticker_id', 'source',
        'is_official', 'is_active', 'is_animated', 'sort_order', 'stickers_count', 'owner_id',
    ];

    protected $casts = [
        'is_official' => 'boolean',
        'is_active' => 'boolean',
        'is_animated' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $pack) {
            if (empty($pack->uuid)) {
                $pack->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function stickers()
    {
        return $this->hasMany(Sticker::class)->where('is_removed', false)->orderBy('position');
    }

    public function cover()
    {
        return $this->belongsTo(Sticker::class, 'cover_sticker_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_sticker_packs')->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Public packs (owner_id NULL) plus the user's own personal pack.
     * Other people's personal packs are never visible.
     */
    public function scopeVisibleTo($query, int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->whereNull('owner_id')->orWhere('owner_id', $userId);
        });
    }
}