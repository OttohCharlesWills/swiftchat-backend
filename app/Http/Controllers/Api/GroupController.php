<?php

namespace App\Http\Controllers\Api;

use App\Events\NewMessage;
use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Group;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class GroupController extends Controller
{
    /**
     * true  = any member can add people and share the link
     * false = only the group's admin (creator) can add people
     */
    private const MEMBERS_CAN_ADD = true;

    // ============================================================
    // CREATE GROUP
    // ============================================================
    //
    // POST /groups
    // { name, description?, user_ids?: [..] }
    //
    // The creator is added automatically. Every other person goes through
    // the SAME rule as "add members": if their allow_group_adds setting is
    // OFF, they are not added — they only get the invite link in a chat.
    //

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'user_ids' => 'nullable|array|max:255',
            'user_ids.*' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $creator = $request->user();

        [$chat, $group] = DB::transaction(function () use ($request, $creator) {
            // ASSUMPTION: chats has `type` and `name` columns (your code
            // already reads $chat->type and $chat->name).
            $chat = Chat::forceCreate([
                'type' => 'group',
                'name' => trim($request->name),
            ]);

            // ASSUMPTION: participants() rows have a user_id column
            // (MessageController already uses participants()->where('user_id')).
            $chat->participants()->forceCreate(['user_id' => $creator->id]);

            $group = Group::create([
                'chat_id' => $chat->id,
                'created_by' => $creator->id,
                'description' => $request->description,
            ]);

            return [$chat, $group];
        });

        $result = $this->addPeople(
            $request,
            $chat,
            $group,
            (array) $request->input('user_ids', [])
        );

        return response()->json(array_merge(
            ['group' => $this->present($chat, $group, (int) $creator->id)],
            $result
        ), 201);
    }

    // ============================================================
    // GROUP INFO
    // ============================================================
    //
    // GET /groups/{chatId}

    public function show(Request $request, $chatId)
    {
        [$chat, $group] = $this->loadGroup($request, $chatId);

        return response()->json(
            $this->present($chat, $group, (int) $request->user()->id)
        );
    }

    // ============================================================
    // EDIT NAME / DESCRIPTION (admin only)
    // ============================================================
    //
    // POST /groups/{chatId}/update   { name?, description? }

    public function update(Request $request, $chatId)
    {
        [$chat, $group] = $this->loadGroup($request, $chatId);

        if (!$this->isAdmin($group, $request)) {
            return $this->fail('Only the group admin can do that.', 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:100',
            'description' => 'sometimes|nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->has('name')) {
            $chat->forceFill(['name' => trim($request->name)])->save();
        }

        if ($request->has('description')) {
            $group->update(['description' => $request->description]);
        }

        return response()->json(
            $this->present($chat->fresh(), $group->fresh(), (int) $request->user()->id)
        );
    }

    // ============================================================
    // ADD PEOPLE
    // ============================================================
    //
    // POST /groups/{chatId}/members   { user_ids: [..] }
    //
    // Answer tells the app exactly what happened to each person:
    //   added            -> now in the group
    //   invited_by_link  -> their "allow others to add me" is OFF, so they
    //                       only received the invite link in their chat
    //   already_members  -> were already in
    //   skipped          -> [{ user_id, reason }]  (blocked, group_full, ...)

    public function addMembers(Request $request, $chatId)
    {
        [$chat, $group] = $this->loadGroup($request, $chatId);

        if (!self::MEMBERS_CAN_ADD && !$this->isAdmin($group, $request)) {
            return $this->fail('Only the group admin can add people.', 403);
        }

        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array|min:1|max:255',
            'user_ids.*' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $result = $this->addPeople($request, $chat, $group, $request->user_ids);

        return response()->json(array_merge(
            ['group' => $this->present($chat, $group, (int) $request->user()->id)],
            $result
        ));
    }

    // ============================================================
    // REMOVE A MEMBER (admin only)
    // ============================================================
    //
    // POST /groups/{chatId}/members/{userId}/remove

    public function removeMember(Request $request, $chatId, $userId)
    {
        [$chat, $group] = $this->loadGroup($request, $chatId);

        if (!$this->isAdmin($group, $request)) {
            return $this->fail('Only the group admin can remove people.', 403);
        }

        $userId = (int) $userId;

        if ($userId === (int) $request->user()->id) {
            return $this->fail('Use "Leave group" to leave.', 422);
        }

        $deleted = $chat->participants()->where('user_id', $userId)->delete();

        if (!$deleted) {
            return $this->fail('That person is not in this group.', 404);
        }

        return response()->json(['message' => 'Removed from the group.']);
    }

    // ============================================================
    // LEAVE GROUP
    // ============================================================
    //
    // POST /groups/{chatId}/leave
    //
    // If the admin leaves, the next member becomes admin. If nobody is
    // left, the group is deleted.

    public function leave(Request $request, $chatId)
    {
        [$chat, $group] = $this->loadGroup($request, $chatId);

        $userId = (int) $request->user()->id;

        $chat->participants()->where('user_id', $userId)->delete();

        $next = $chat->participants()->value('user_id');

        if (!$next) {
            $chat->delete(); // cascades to messages and the groups row

            return response()->json(['message' => 'You left the group.']);
        }

        if ((int) $group->created_by === $userId) {
            $group->update(['created_by' => $next]);
        }

        return response()->json(['message' => 'You left the group.']);
    }

    // ============================================================
    // INVITE LINK
    // ============================================================

    // GET /groups/{chatId}/invite
    public function invite(Request $request, $chatId)
    {
        [$chat, $group] = $this->loadGroup($request, $chatId);

        return response()->json([
            'invite_link_enabled' => $group->invite_link_enabled,
            'invite_link' => $group->invite_link_enabled ? $group->invite_link : null,
        ]);
    }

    // POST /groups/{chatId}/invite/reset   (old link stops working)
    public function resetInvite(Request $request, $chatId)
    {
        [$chat, $group] = $this->loadGroup($request, $chatId);

        if (!$this->isAdmin($group, $request)) {
            return $this->fail('Only the group admin can do that.', 403);
        }

        $group->resetInviteCode();

        return response()->json([
            'invite_link_enabled' => $group->invite_link_enabled,
            'invite_link' => $group->fresh()->invite_link,
        ]);
    }

    // POST /groups/{chatId}/invite/enabled   { enabled: true|false }
    public function setInviteEnabled(Request $request, $chatId)
    {
        [$chat, $group] = $this->loadGroup($request, $chatId);

        if (!$this->isAdmin($group, $request)) {
            return $this->fail('Only the group admin can do that.', 403);
        }

        $request->validate(['enabled' => 'required|boolean']);

        $group->update(['invite_link_enabled' => $request->boolean('enabled')]);

        return response()->json([
            'invite_link_enabled' => $group->invite_link_enabled,
            'invite_link' => $group->invite_link_enabled ? $group->invite_link : null,
        ]);
    }

    // ============================================================
    // JOIN BY LINK
    // ============================================================

    // GET /groups/join/{code}  — what the "Join this group?" screen shows
    public function previewInvite(Request $request, string $code)
    {
        $group = Group::where('invite_code', $code)
            ->where('invite_link_enabled', true)
            ->first();

        $chat = $group?->chat;

        if (!$group || !$chat || ($chat->type ?? '') !== 'group') {
            return $this->fail('This invite link is invalid or has expired.', 404);
        }

        $userId = (int) $request->user()->id;

        return response()->json([
            'chat_id' => $chat->id,
            'name' => $chat->name,
            'group_avatar' => $chat->group_avatar ?? null, // ASSUMPTION: column name
            'description' => $group->description,
            'member_count' => $chat->participants()->count(),
            'already_member' => $chat->participants()->where('user_id', $userId)->exists(),
        ]);
    }

    // POST /groups/join/{code}
    public function join(Request $request, string $code)
    {
        $group = Group::where('invite_code', $code)
            ->where('invite_link_enabled', true)
            ->first();

        $chat = $group?->chat;

        if (!$group || !$chat || ($chat->type ?? '') !== 'group') {
            return $this->fail('This invite link is invalid or has expired.', 404);
        }

        $userId = (int) $request->user()->id;

        $isMember = $chat->participants()->where('user_id', $userId)->exists();

        if (!$isMember) {
            if ($chat->participants()->count() >= $group->max_members) {
                return $this->fail('This group is full.', 422);
            }

            $chat->participants()->forceCreate(['user_id' => $userId]);
        }

        return response()->json([
            'chat_id' => $chat->id,
            'name' => $chat->name,
            'group_avatar' => $chat->group_avatar ?? null,
            'already_member' => $isMember,
        ]);
    }

    // ============================================================
    // MY PRIVACY SETTING: "Allow others to add me to groups"
    // ============================================================

    // GET /settings/privacy
    public function privacy(Request $request)
    {
        return response()->json([
            'allow_group_adds' => (bool) ($request->user()->allow_group_adds ?? true),
        ]);
    }

    // POST /settings/privacy   { allow_group_adds: true|false }
    public function updatePrivacy(Request $request)
    {
        $request->validate(['allow_group_adds' => 'required|boolean']);

        $user = $request->user();

        $user->forceFill([
            'allow_group_adds' => $request->boolean('allow_group_adds'),
        ])->save();

        return response()->json([
            'allow_group_adds' => (bool) $user->allow_group_adds,
        ]);
    }

    // ============================================================
    // THE HEART OF IT: add people, respecting allow_group_adds
    // ============================================================

    private function addPeople(Request $request, Chat $chat, Group $group, array $userIds): array
    {
        $adder = $request->user();

        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $userIds = array_values(array_diff($userIds, [(int) $adder->id]));

        $existing = $chat->participants()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $room = (int) $group->max_members - count($existing);

        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        $added = [];
        $invitedByLink = [];
        $alreadyMembers = [];
        $skipped = [];

        foreach ($userIds as $id) {
            $user = $users->get($id);

            if (!$user) {
                $skipped[] = ['user_id' => $id, 'reason' => 'not_found'];
                continue;
            }

            if (in_array($id, $existing, true)) {
                $alreadyMembers[] = $id;
                continue;
            }

            // They blocked the person who is trying to add them: do nothing,
            // don't even send the link.
            if ($this->hasBlocked($id, (int) $adder->id)) {
                $skipped[] = ['user_id' => $id, 'reason' => 'blocked'];
                continue;
            }

            $allowsAdds = (bool) ($user->allow_group_adds ?? true);

            if ($allowsAdds) {
                if ($room <= 0) {
                    $skipped[] = ['user_id' => $id, 'reason' => 'group_full'];
                    continue;
                }

                $chat->participants()->forceCreate(['user_id' => $id]);

                $existing[] = $id;
                $room--;
                $added[] = $id;
                continue;
            }

            // allow_group_adds is OFF: NOT added. Only the link goes to
            // their chat with the person who tried to add them.
            if (!$group->invite_link_enabled) {
                $skipped[] = ['user_id' => $id, 'reason' => 'invite_link_disabled'];
                continue;
            }

            if ($this->sendInviteLinkMessage($request, $adder, $user, $chat, $group)) {
                $invitedByLink[] = $id;
            } else {
                $skipped[] = ['user_id' => $id, 'reason' => 'could_not_send_link'];
            }
        }

        return [
            'added' => $added,
            'invited_by_link' => $invitedByLink,
            'already_members' => $alreadyMembers,
            'skipped' => $skipped,
        ];
    }

    /**
     * Sends the invite link as a normal text message in the private chat
     * between the person adding and the person who turned adds off.
     */
    private function sendInviteLinkMessage(
        Request $request,
        User $adder,
        User $target,
        Chat $groupChat,
        Group $group
    ): bool {
        try {
            $privateChat = $this->privateChatBetween($adder, $target);

            $body = "{$adder->name} invited you to join the group \"{$groupChat->name}\".\n"
                . "Tap the link to join:\n"
                . $group->invite_link;

            $message = Message::create([
                'chat_id' => $privateChat->id,
                'sender_id' => $adder->id,
                'body' => $body,
                'type' => 'text',
                'is_deleted' => false,
                'deleted_for_user_ids' => null,
            ]);

            $message->load(['sender', 'replyTo.sender', 'sticker']);

            try {
                broadcast(new NewMessage($message));
            } catch (\Throwable $e) {
                Log::warning('Group invite broadcast failed', [
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Push notification AFTER the answer has been sent (same idea
            // as MessageController::store). This calls the existing push
            // code — see the note about making that method public.
            app()->terminating(function () use ($request, $privateChat, $message) {
                try {
                    app(MessageController::class)
                        ->sendPushToOtherParticipants($request, $privateChat, $message);
                } catch (\Throwable $e) {
                    Log::warning('Group invite push failed', [
                        'message_id' => $message->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning('Could not send group invite link', [
                'group_chat_id' => $groupChat->id,
                'target_id' => $target->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** Finds the one-to-one chat between two people, or creates it. */
    private function privateChatBetween(User $a, User $b): Chat
    {
        $chat = Chat::where('type', 'private')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $a->id))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $b->id))
            ->first();

        if ($chat) {
            return $chat;
        }

        // TODO: if your POST /chats/private sets any extra columns when it
        // creates a private chat, copy them here so both ways match.
        $chat = Chat::forceCreate(['type' => 'private']);

        $chat->participants()->forceCreate(['user_id' => $a->id]);
        $chat->participants()->forceCreate(['user_id' => $b->id]);

        return $chat;
    }

    // ============================================================
    // HELPERS
    // ============================================================

    /** Has $ownerId blocked $otherId in their contacts? */
    private function hasBlocked(int $ownerId, int $otherId): bool
    {
        return Contact::where('user_id', $ownerId)
            ->where('contact_user_id', $otherId)
            ->where('is_blocked', true)
            ->exists();
    }

    /** Loads the group chat, checks the caller is a member, makes sure the
     *  groups row exists (older groups) and that it has an admin. */
    private function loadGroup(Request $request, $chatId): array
    {
        $chat = Chat::findOrFail($chatId);

        abort_unless(($chat->type ?? '') === 'group', 404, 'Group not found.');

        $isMember = $chat->participants()
            ->where('user_id', $request->user()->id)
            ->exists();

        abort_unless($isMember, 403, 'You are not part of this group.');

        $group = Group::firstOrCreate(['chat_id' => $chat->id]);

        // Groups made before this feature have no admin yet: the first
        // member becomes admin.
        if (!$group->created_by) {
            $first = $chat->participants()->value('user_id');

            if ($first) {
                $group->update(['created_by' => $first]);
            }
        }

        return [$chat, $group];
    }

    private function isAdmin(Group $group, Request $request): bool
    {
        return (int) $group->created_by === (int) $request->user()->id;
    }

    private function fail(string $message, int $status)
    {
        return response()->json(['message' => $message], $status);
    }

    /** The group as the app wants it. */
    private function present(Chat $chat, Group $group, int $viewerId): array
    {
        $memberIds = $chat->participants()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $members = User::whereIn('id', $memberIds)->get()->map(fn ($u) => [
            'id' => (int) $u->id,
            'name' => $u->name,
            'avatar_url' => $u->avatar_url ?? null,
            'phone_number' => $u->phone_number ?? null,
            'is_admin' => (int) $group->created_by === (int) $u->id,
        ])->values();

        return [
            'chat_id' => $chat->id,
            'name' => $chat->name,
            'group_avatar' => $chat->group_avatar ?? null, // ASSUMPTION: column name
            'description' => $group->description,
            'created_by' => $group->created_by,
            'is_admin' => (int) $group->created_by === $viewerId,
            'member_count' => count($memberIds),
            'max_members' => $group->max_members,
            'invite_link_enabled' => $group->invite_link_enabled,
            'invite_link' => $group->invite_link_enabled ? $group->invite_link : null,
            'members' => $members,
        ];
    }
}