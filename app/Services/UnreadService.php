<?php

namespace App\Services;

use App\Models\Message;

/**
 * One definition of "unread", used by BOTH the chat list (per-chat number)
 * and the app icon / push notifications (total), so they can never
 * disagree.
 *
 * A message counts as unread for a user when:
 *  - someone else sent it,
 *  - it arrived after the user last opened that chat
 *    (chat_participants.last_read_at, which markRead() updates),
 *  - it was not deleted for everyone,
 *  - and the user did not delete it "for me" (deleted_for_user_ids).
 */
class UnreadService
{
    /**
     * Total unread messages across every chat the user is in.
     */
    public static function forUser(int $userId): int
    {
        return self::baseQuery($userId)->count();
    }

    /**
     * Unread messages per chat, as [chat_id => count].
     * Chats with nothing unread are simply absent from the array.
     */
    public static function perChat(int $userId): array
    {
        return self::baseQuery($userId)
            ->selectRaw('messages.chat_id, COUNT(*) as unread')
            ->groupBy('messages.chat_id')
            ->pluck('unread', 'chat_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private static function baseQuery(int $userId)
    {
        return Message::query()
            // Only chats this user is actually in, and read position there.
            ->join('chat_participants as cp', function ($join) use ($userId) {
                $join->on('cp.chat_id', '=', 'messages.chat_id')
                    ->where('cp.user_id', '=', $userId);
            })
            ->where('messages.sender_id', '!=', $userId)
            ->where(function ($query) {
                $query->whereNull('cp.last_read_at')
                    ->orWhereColumn('messages.created_at', '>', 'cp.last_read_at');
            })
            ->where(function ($query) {
                $query->whereNull('messages.is_deleted')
                    ->orWhere('messages.is_deleted', false);
            })
            ->where(function ($query) use ($userId) {
                $query->whereNull('messages.deleted_for_user_ids')
                    ->orWhereJsonDoesntContain(
                        'messages.deleted_for_user_ids',
                        $userId
                    );
            });
    }
}