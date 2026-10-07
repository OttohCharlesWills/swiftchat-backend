<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Call extends Model
{
    protected $fillable = [
        'caller_id', 'callee_id', 'chat_id', 'type', 'status', 'room_name',
        'answered_at', 'ended_at', 'duration_seconds', 'ended_by', 'end_reason',
    ];

    protected $casts = [
        'answered_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $call) {
            if (empty($call->uuid)) {
                $call->uuid = (string) Str::uuid();
            }

            if (empty($call->room_name)) {
                $call->room_name = 'call_' . $call->uuid;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function caller()
    {
        return $this->belongsTo(User::class, 'caller_id');
    }

    public function callee()
    {
        return $this->belongsTo(User::class, 'callee_id');
    }

    /** Calls that are ringing or in progress for this user. */
    public function scopeActiveFor($query, int $userId)
    {
        return $query->whereIn('status', ['ringing', 'accepted'])
            ->where(function ($w) use ($userId) {
                $w->where('caller_id', $userId)->orWhere('callee_id', $userId);
            });
    }

    /**
     * Closes calls that were left hanging (app killed, no internet...) so
     * nobody stays "busy" forever.
     */
    public static function expireStale(): void
    {
        $ring = (int) config('calls.ring_seconds', 45);

        static::where('status', 'ringing')
            ->where('created_at', '<', now()->subSeconds($ring + 15))
            ->update(['status' => 'missed', 'ended_at' => now(), 'end_reason' => 'timeout']);

        static::where('status', 'accepted')
            ->where('answered_at', '<', now()->subHours(4))
            ->update(['status' => 'ended', 'ended_at' => now(), 'end_reason' => 'stale']);
    }

    /**
     * The call as seen by one person: "other_user" is the person on the
     * other end, shown by the name this viewer saved them as.
     */
    public function toApi(User $viewer): array
    {
        $isCaller = (int) $viewer->id === (int) $this->caller_id;
        $other = $isCaller ? $this->callee : $this->caller;

        $savedName = $other
            ? Contact::where('user_id', $viewer->id)->where('contact_user_id', $other->id)->value('saved_name')
            : null;

        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'direction' => $isCaller ? 'outgoing' : 'incoming',
            'type' => $this->type,
            'chat_id' => $this->chat_id,
            'other_user' => [
                'id' => $other?->id,
                'name' => $savedName ?: ($other?->name ?? 'Unknown'),
                'avatar_url' => $other?->avatar_url,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'answered_at' => $this->answered_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'duration_seconds' => $this->duration_seconds,
            'end_reason' => $this->end_reason,
        ];
    }
}
