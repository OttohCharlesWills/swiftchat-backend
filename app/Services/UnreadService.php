<?php

namespace App\Services;

use App\Models\Message;
use Illuminate\Support\Facades\DB;

class UnreadService
{
    /**
     * Total number of unread messages across every chat the user is in.
     *
     * Used for the app-icon badge and for the notification count.
     * Messages the user sent, messages deleted for everyone, and messages
     * the user deleted "for me" are not counted.
     */
    public static function forUser(int $userId): int
    {
        $chatIds = DB::table('chat_participants')
            ->where('user_id', $userId)
            ->pluck('chat_id');

        if ($chatIds->isEmpty()) {
            return 0;
        }

        return Message::query()
            ->whereIn('chat_id', $chatIds)
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->where(function ($query) {
                $query->whereNull('is_deleted')
                    ->orWhere('is_deleted', false);
            })
            ->whereDoesntHave('deletions', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->count();
    }
}