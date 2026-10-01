<?php

namespace App\Services;

use App\Models\Message;
use Illuminate\Support\Facades\DB;

class UnreadService
{
    /**
     * Total number of unread messages across every chat the user is in.
     *
     * Used for the number on the app icon and in notifications.
     * Not counted: messages the user sent, messages deleted for everyone,
     * and messages the user deleted "for me" (deleted_for_user_ids).
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
            ->where(function ($query) use ($userId) {
                $query->whereNull('deleted_for_user_ids')
                    ->orWhereJsonDoesntContain('deleted_for_user_ids', $userId);
            })
            ->count();
    }
}