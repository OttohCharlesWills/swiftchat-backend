<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class GroupCall extends Model
{
    protected $fillable = [
        'chat_id', 'started_by', 'status',
        'started_at', 'ended_at', 'duration_seconds', 'end_reason',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $call) {
            if (empty($call->uuid)) {
                $call->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }

    public function starter()
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function participants()
    {
        return $this->hasMany(GroupCallParticipant::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /** Active group calls this user is currently JOINED in (so: "busy"). */
    public static function activeForUser(int $userId)
    {
        return static::active()->whereHas('participants', function ($p) use ($userId) {
            $p->where('user_id', $userId)->where('status', 'joined');
        });
    }

    /**
     * Closes calls nobody is in any more (everyone's app died, no
     * internet...) so people don't stay "busy" forever.
     */
    public static function expireStale(): void
    {
        static::active()
            ->where('created_at', '<', now()->subMinutes(2))
            ->whereDoesntHave('participants', function ($p) {
                $p->where('status', 'joined');
            })
            ->update([
                'status' => 'ended',
                'ended_at' => now(),
                'end_reason' => 'stale',
            ]);

        static::active()
            ->where('created_at', '<', now()->subHours(6))
            ->update([
                'status' => 'ended',
                'ended_at' => now(),
                'end_reason' => 'stale',
            ]);
    }

    /**
     * The call as seen by one person: names are the ones THIS viewer saved
     * their contacts as, and the viewer's own row is called "You".
     */
    public function toApi(User $viewer, ?array $savedNames = null): array
    {
        $this->loadMissing(['chat', 'starter', 'participants.user']);

        if ($savedNames === null) {
            $savedNames = Contact::where('user_id', $viewer->id)
                ->whereIn('contact_user_id', $this->participants->pluck('user_id'))
                ->pluck('saved_name', 'contact_user_id')
                ->all();
        }

        $me = $this->participants->firstWhere('user_id', $viewer->id);

        return [
            'id' => $this->uuid,
            'chat_id' => $this->chat_id,
            'chat_name' => $this->chat?->name ?: 'Group',
            'status' => $this->status,
            'started_by' => $this->started_by,
            'my_status' => $me?->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'duration_seconds' => $this->duration_seconds,
            'participants' => $this->participants->map(function ($p) use ($viewer, $savedNames) {
                $isMe = (int) $p->user_id === (int) $viewer->id;

                return [
                    'user_id' => (int) $p->user_id,
                    'name' => $isMe
                        ? 'You'
                        : ($savedNames[$p->user_id] ?? $p->user?->name ?? 'Unknown'),
                    'avatar_url' => $p->user?->avatar_url,
                    'status' => $p->status,
                ];
            })->values()->all(),
        ];
    }
}