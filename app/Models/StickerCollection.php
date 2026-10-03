<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StickerCollection extends Model
{
    protected $fillable = ['user_id', 'name'];

    protected static function booted(): void
    {
        static::creating(function (self $collection) {
            if (empty($collection->uuid)) {
                $collection->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function stickers()
    {
        return $this->belongsToMany(Sticker::class, 'sticker_collection_items', 'collection_id', 'sticker_id')
            ->withPivot('position')
            ->orderBy('sticker_collection_items.position');
    }
}