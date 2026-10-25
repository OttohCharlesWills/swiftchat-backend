<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Group extends Model
{
    protected $fillable = [
        'chat_id',
        'created_by',
        'description',
        'invite_code',
        'invite_link_enabled',
        'max_members',
    ];

    protected $casts = [
        'invite_link_enabled' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Every group gets its own invite code the moment it is created.
        static::creating(function (self $group) {
            if (empty($group->invite_code)) {
                $group->invite_code = static::newInviteCode();
            }
        });
    }

    public static function newInviteCode(): string
    {
        do {
            $code = Str::random(24);
        } while (static::where('invite_code', $code)->exists());

        return $code;
    }

    /** Makes the old link stop working and gives the group a new one. */
    public function resetInviteCode(): void
    {
        $this->update(['invite_code' => static::newInviteCode()]);
    }

    /** The link people share. Change the base to your real domain / app link. */
    public function getInviteLinkAttribute(): string
    {
        return rtrim(config('app.url'), '/') . '/join/' . $this->invite_code;
    }

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}