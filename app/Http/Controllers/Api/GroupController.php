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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class GroupController extends Controller
{
    private const MEMBERS_CAN_ADD = true;
    private const MAX_MEMBERS = 1500;

    // ============================================================
    // CREATE GROUP
    // ============================================================

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'user_ids' => 'nullable|array|max:' . self::MAX_MEMBERS,
            'user_ids.*' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $creator = $request->user();

        [$chat, $group] = DB::transaction(function () use ($request, $creator) {

            $chat = Chat::forceCreate([
                'type' => 'group',
                'name' => trim($request->name),
            ]);

            // Creator is automatically an admin.
            $chat->participants()->forceCreate([
                'user_id' => $creator->id,
                'role' => 'admin',
            ]);

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

        return response()->json(array_merge([
            'group' => $this->present(
                $chat->fresh(),
                $group->fresh(),
                (int) $creator->id
            )
        ], $result), 201);
    }

    // ============================================================
    // GROUP INFO
    // ============================================================

    public function show(Request $request, $chatId)
    {
        [$chat, $group] = $this->loadGroup($request, $chatId);

        return response()->json(
            $this->present($chat, $group, (int) $request->user()->id)
        );
    }

    // ============================================================
    // UPDATE GROUP NAME / DESCRIPTION
    // ============================================================

    public function update(Request $request, $chatId)
    {
        [$chat, $group] = $this->loadGroup($request, $chatId);

        if (!$this->isAdmin($group, $request)) {
            return $this->fail('Only group admins can update group information.', 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:100',
            'description' => 'sometimes|nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->has('name')) {
            $chat->forceFill([
                'name' => trim($request->name)
            ])->save();
        }

        if ($request->has('description')) {
            $group->update([
                'description' => $request->description
            ]);
        }

        return response()->json(
            $this->present($chat->fresh(), $group->fresh(), (int) $request->user()->id)
        );
    }

    // ============================================================
    // GROUP AVATAR
    // ============================================================
    //
    // POST /groups/{chatId}/avatar
    // multipart/form-data:
    // avatar = image
    //
    // Stored on chats.group_avatar.
    // ============================================================

    
public function updateAvatar(Request $request, $chatId)
{
    [$chat, $group] = $this->loadGroup($request, $chatId);

    if (!$this->isAdmin($group, $request)) {
        return $this->fail(
            'Only group admins can change the group picture.',
            403
        );
    }

    $validator = Validator::make($request->all(), [
        'file' => 'required|image|max:5120',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        $uploaded = cloudinary()->uploadApi()->upload(
            $request->file('file')->getRealPath(),
            [
                'folder' => 'group_avatars',
            ]
        );

        $chat->group_avatar = $uploaded['secure_url'];
        $chat->save();

        return response()->json([
            'message' => 'Group picture updated successfully.',
            'group_avatar' => $chat->fresh()->group_avatar,
        ]);

    } catch (\Throwable $e) {
        Log::error('Group avatar upload failed', [
            'chat_id' => $chat->id,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'message' => 'Could not upload group picture. Please try again.',
        ], 500);
    }
}


    // ============================================================
    // ADD MEMBERS
    // ============================================================

    public function addMembers(Request $request, $chatId)
    {
        [$chat, $group] = $this->loadGroup($request, $chatId);

        if (!self::MEMBERS_CAN_ADD && !$this->isAdmin($group, $request)) {
            return $this->fail('Only group admins can add people.', 403);
        }

        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array|min:1|max:' . self::MAX_MEMBERS,
            'user_ids.*' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $result = $this->addPeople(
            $request,
            $chat,
            $group,
            $request->input('user_ids')
        );

        return response()->json(array_merge([
            'group' => $this->present($chat->fresh(), $group->fresh(), (int) $request->user()->id),
        ], $result));
    }

    // ============================================================
    // PROMOTE MEMBER TO ADMIN
    // ============================================================
    //
    // POST /groups/{chatId}/members/{userId}/promote
    // ============================================================

    public function promoteAdmin(Request $request, $chatId, $userId)
    {
        [$chat, $group] = $this->loadGroup($request, $chatId);

        if (!$this->isAdmin($group, $request)) {
            return $this->fail('Only group admins can promote members.', 403);
        }

        $userId = (int) $userId;

        $participant = $chat->participants()
            ->where('user_id', $userId)
            ->first();

        if (!$participant) {
            return $this->fail('This person is not a member of the group.', 404);
        }

        if ($userId === (int) $group->created_by) {
            return $this->fail('The group owner is already an admin.', 422);
        }

        if ($participant->role === 'admin') {
            return $this->fail('This person is already an admin.', 422);
        }

        $participant->update(['role' => 'admin']);

        return response()->json([
            'message' => 'Member promoted to admin successfully.',
            'group' => $this->present($chat->fresh(), $group->fresh(), (int) $request->user()->id),
        ]);
    }

    // ============================================================
    // DEMOTE ADMIN
    // ============================================================
    //
    // POST /groups/{chatId}/members/{userId}/demote
    // ============================================================

    public function demoteAdmin(Request $request, $chatId, $userId)
    {
        [$chat, $group] = $this->loadGroup($request, $chatId);

        if (!$this->isAdmin($group, $request)) {
            return $this->fail('Only group admins can demote admins.', 403);
        }

        $userId = (int) $userId;

        // Nobody can demote the original/current owner.
        if ($userId === (int) $group->created_by) {
            return $this->fail('The group owner cannot be demoted.', 403);
        }

        if ($userId === (int) $request->user()->id) {
            return $this->fail('You cannot demote yourself.', 422);
        }

        $participant = $chat->participants()
            ->where('user_id', $userId)
            ->first();

        if (!$participant) {
            return $this->fail('This person is not a group member.', 404);
        }

        if ($participant->role !== 'admin') {
            return $this->fail('This person is not an admin.', 422);
        }

        $participant->update(['role' => 'member']);

        return response()->json([
            'message' => 'Admin demoted successfully.',
            'group' => $this->present($chat->fresh(), $group->fresh(), (int) $request->user()->id),
        ]);
    }

    // ============================================================
    // TRANSFER GROUP OWNERSHIP
    // ============================================================
    //
    // POST /groups/{chatId}/transfer-ownership
    // { user_id: 25 }
    //
    // Only the current owner can transfer ownership.
    // The recipient must already be an admin.
    // ============================================================

    public function transferOwnership(Request $request, $chatId)
    {
        [$chat, $group] = $this->loadGroup($request, $chatId);

        if (!$this->isOwner($group, $request)) {
            return $this->fail('Only the group owner can transfer ownership.', 403);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $newOwnerId = (int) $request->user_id;

        if ($newOwnerId === (int) $group->created_by) {
            return $this->fail('You are already the group owner.', 422);
        }

        $newOwner = $chat->participants()
            ->where('user_id', $newOwnerId)
            ->first();

        if (!$newOwner) {
            return $this->fail('The new owner must be a group member.', 404);
        }

        if ($newOwner->role !== 'admin') {
            return $this->fail('Promote this member to admin before transferring ownership.', 422);
        }

        DB::transaction(function () use ($group, $newOwner) {
            $group->update([
                'created_by' => $newOwner->user_id,
            ]);

            // Both the new owner and previous owner remain admins.
            $newOwner->update(['role' => 'admin']);
        });

        return response()->json([
            'message' => 'Group ownership transferred successfully.',
            'group' => $this->present(
                $chat->fresh(),
                $group->fresh(),
                (int) $request->user()->id
            ),
        ]);
    }

    // ============================================================
    // REMOVE MEMBER
    // ============================================================

    public function removeMember(Request $request, $chatId, $userId)
    {
        [$chat, $group] = $this->loadGroup($request, $chatId);

        if (!$this->isAdmin($group, $request)) {
            return $this->fail('Only group admins can remove members.', 403);
        }

        $userId = (int) $userId;

        if ($userId === (int) $request->user()->id) {
            return $this->fail('Use the leave group endpoint to leave.', 422);
        }

        // Absolute owner protection.
        if ($userId === (int) $group->created_by) {
            return $this->fail('The group owner cannot be removed.', 403);
        }

        $deleted = $chat->participants()
            ->where('user_id', $userId)
            ->delete();

        if (!$deleted) {
            return $this->fail('That person is not in this group.', 404);
        }

        return response()->json([
            'message' => 'Member removed successfully.',
        ]);
    }

    // ============================================================
    // LEAVE GROUP
    // ============================================================

    public function leave(Request $request, $chatId)
    {
        [$chat, $group] = $this->loadGroup($request, $chatId);

        $userId = (int) $request->user()->id;

        // Owner must transfer ownership first.
        if ($this->isOwner($group, $request)) {
            return $this->fail(
                'You are the group owner. Transfer ownership to another admin before leaving.',
                422
            );
        }

        $chat->participants()
            ->where('user_id', $userId)
            ->delete();

        if ($chat->participants()->count() === 0) {
            $chat->delete();

            return response()->json([
                'message' => 'You left the group.',
            ]);
        }

        return response()->json([
            'message' => 'You left the group.',
        ]);
    }

    // ============================================================
    // INVITE LINK
    // ============================================================

    public function invite(Request $request, $chatId)
    {
        [$chat, $group] = $this->loadGroup($request, $chatId);

        return response()->json([
            'invite_link_enabled' => $group->invite_link_enabled,
            'invite_link' => $group->invite_link_enabled ? $group->invite_link : null,
        ]);
    }

    public function resetInvite(Request $request, $chatId)
    {
        [$chat, $group] = $this->loadGroup($request, $chatId);

        if (!$this->isAdmin($group, $request)) {
            return $this->fail('Only group admins can reset the invite link.', 403);
        }

        $group->resetInviteCode();

        return response()->json([
            'invite_link_enabled' => $group->invite_link_enabled,
            'invite_link' => $group->fresh()->invite_link,
        ]);
    }

    public function setInviteEnabled(Request $request, $chatId)
    {
        [$chat, $group] = $this->loadGroup($request, $chatId);

        if (!$this->isAdmin($group, $request)) {
            return $this->fail('Only group admins can change invite settings.', 403);
        }

        $request->validate([
            'enabled' => 'required|boolean'
        ]);

        $group->update([
            'invite_link_enabled' => $request->boolean('enabled')
        ]);

        return response()->json([
            'invite_link_enabled' => $group->invite_link_enabled,
            'invite_link' => $group->invite_link_enabled ? $group->invite_link : null,
        ]);
    }

    // ============================================================
    // JOIN BY INVITE LINK
    // ============================================================

    public function previewInvite(Request $request, string $code)
    {
        $group = Group::where('invite_code', $code)
            ->where('invite_link_enabled', true)
            ->first();

        $chat = $group?->chat;

        if (!$group || !$chat || $chat->type !== 'group') {
            return $this->fail('This invite link is invalid or has expired.', 404);
        }

        $userId = (int) $request->user()->id;

        return response()->json([
            'chat_id' => $chat->id,
            'name' => $chat->name,
            'group_avatar' => $chat->group_avatar,
            'description' => $group->description,
            'member_count' => $chat->participants()->count(),
            'already_member' => $chat->participants()
                ->where('user_id', $userId)->exists(),
        ]);
    }

    public function join(Request $request, string $code)
    {
        $group = Group::where('invite_code', $code)
            ->where('invite_link_enabled', true)
            ->first();

        $chat = $group?->chat;

        if (!$group || !$chat || $chat->type !== 'group') {
            return $this->fail('This invite link is invalid or has expired.', 404);
        }

        $userId = (int) $request->user()->id;

        $isMember = $chat->participants()
            ->where('user_id', $userId)->exists();

        if (!$isMember) {
            if ($chat->participants()->count() >= self::MAX_MEMBERS) {
                return $this->fail('This group is full.', 422);
            }

            $chat->participants()->forceCreate([
                'user_id' => $userId,
                'role' => 'member',
            ]);
        }

        return response()->json([
            'chat_id' => $chat->id,
            'name' => $chat->name,
            'group_avatar' => $chat->group_avatar,
            'already_member' => $isMember,
        ]);
    }

    // ============================================================
    // PRIVACY SETTINGS
    // ============================================================

    public function privacy(Request $request)
    {
        return response()->json([
            'allow_group_adds' => (bool) ($request->user()->allow_group_adds ?? true),
        ]);
    }

    public function updatePrivacy(Request $request)
    {
        $request->validate([
            'allow_group_adds' => 'required|boolean'
        ]);

        $user = $request->user();

        $user->forceFill([
            'allow_group_adds' => $request->boolean('allow_group_adds'),
        ])->save();

        return response()->json([
            'allow_group_adds' => (bool) $user->allow_group_adds,
        ]);
    }

    // ============================================================
    // ADD PEOPLE HELPER
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

        $room = self::MAX_MEMBERS - count($existing);

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

                $chat->participants()->forceCreate([
                    'user_id' => $id,
                    'role' => 'member',
                ]);

                $existing[] = $id;
                $room--;
                $added[] = $id;

                continue;
            }

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

    // ============================================================
    // SEND INVITE LINK MESSAGE
    // ============================================================

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

    private function privateChatBetween(User $a, User $b): Chat
    {
        $chat = Chat::where('type', 'private')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $a->id))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $b->id))
            ->first();

        if ($chat) {
            return $chat;
        }

        $chat = Chat::forceCreate(['type' => 'private']);

        $chat->participants()->forceCreate([
            'user_id' => $a->id,
            'role' => 'member',
        ]);

        $chat->participants()->forceCreate([
            'user_id' => $b->id,
            'role' => 'member',
        ]);

        return $chat;
    }

    // ============================================================
    // HELPERS
    // ============================================================

    private function hasBlocked(int $ownerId, int $otherId): bool
    {
        return Contact::where('user_id', $ownerId)
            ->where('contact_user_id', $otherId)
            ->where('is_blocked', true)
            ->exists();
    }

    private function loadGroup(Request $request, $chatId): array
    {
        $chat = Chat::findOrFail($chatId);

        abort_unless($chat->type === 'group', 404, 'Group not found.');

        $isMember = $chat->participants()
            ->where('user_id', $request->user()->id)
            ->exists();

        abort_unless($isMember, 403, 'You are not part of this group.');

        $group = Group::firstOrCreate([
            'chat_id' => $chat->id
        ]);

        // Repair older groups without an owner.
        if (!$group->created_by) {
            $first = $chat->participants()
                ->orderBy('id')
                ->first();

            if ($first) {
                DB::transaction(function () use ($group, $first) {
                    $group->update([
                        'created_by' => $first->user_id
                    ]);

                    $first->update(['role' => 'admin']);
                });
            }
        }

        // The owner must always have admin role.
        if ($group->created_by) {
            $chat->participants()
                ->where('user_id', $group->created_by)
                ->where('role', '!=', 'admin')
                ->update(['role' => 'admin']);
        }

        return [$chat, $group];
    }

    private function isOwner(Group $group, Request $request): bool
    {
        return (int) $group->created_by === (int) $request->user()->id;
    }

    private function isAdmin(Group $group, Request $request): bool
    {
        $userId = (int) $request->user()->id;

        if ((int) $group->created_by === $userId) {
            return true;
        }

        return $group->chat->participants()
            ->where('user_id', $userId)
            ->where('role', 'admin')
            ->exists();
    }

    private function fail(string $message, int $status)
    {
        return response()->json([
            'message' => $message
        ], $status);
    }

    // ============================================================
    // API RESPONSE
    // ============================================================

    private function present(Chat $chat, Group $group, int $viewerId): array
    {
        $participants = $chat->participants()
            ->with('user')
            ->get();

        $members = $participants
            ->filter(fn ($participant) => $participant->user !== null)
            ->map(function ($participant) use ($group) {
                $user = $participant->user;

                $isOwner = (int) $group->created_by === (int) $user->id;

                return [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'avatar_url' => $user->avatar_url ?? null,
                    'phone_number' => $user->phone_number ?? null,
                    'role' => $isOwner ? 'admin' : $participant->role,
                    'is_admin' => $isOwner || $participant->role === 'admin',
                    'is_owner' => $isOwner,
                ];
            })
            ->values();

        $viewer = $participants->first(
            fn ($participant) => (int) $participant->user_id === $viewerId
        );

        $isOwner = (int) $group->created_by === $viewerId;
        $isAdmin = $isOwner || ($viewer && $viewer->role === 'admin');

        return [
            'chat_id' => (int) $chat->id,
            'name' => $chat->name,
            'group_avatar' => $chat->group_avatar,
            'description' => $group->description,
            'created_by' => (int) $group->created_by,
            'is_admin' => (bool) $isAdmin,
            'is_owner' => $isOwner,
            'member_count' => $participants->count(),
            'max_members' => self::MAX_MEMBERS,
            'invite_link_enabled' => (bool) $group->invite_link_enabled,
            'invite_link' => $group->invite_link_enabled ? $group->invite_link : null,
            'members' => $members,
        ];
    }
}
