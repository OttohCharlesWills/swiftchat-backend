<?php

namespace App\Http\Controllers\Api;

use App\Events\NewMessage;
use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Message;
use App\Models\Update;
use App\Models\UpdateView;
use App\Services\CloudinaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UpdateController extends Controller
{
    /**
     * The most groups one Update can be tagged in.
     */
    private const MAX_TAGGED_GROUPS = 10;

    /**
     * The message type used for "a status was tagged in this group".
     * (The Flutter app draws it as a status card.)
     */
    private const GROUP_STATUS_TYPE = 'group_status';

    public function __construct(
        protected CloudinaryService $cloudinary
    ) {
    }

    /**
     * Create a new Update.
     *
     * Optional: group_ids = the groups (chat ids) to tag. A "group_status"
     * message is posted in each group the user belongs to.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:text,image,video',
            'caption' => 'nullable|string|max:5000',
            'duration_hours' => 'required|integer|in:24,48',
            'allow_repost' => 'nullable|boolean',
            'media' => 'required_if:type,image,video|file|max:102400',
            'group_ids' => 'nullable|array|max:' . self::MAX_TAGGED_GROUPS,
            'group_ids.*' => 'integer',
        ]);

        // Validation does NOT cast types. Multipart/form fields arrive as
        // strings, and Carbon 3 requires int|float, so cast explicitly.
        $durationHours = (int) $validated['duration_hours'];

        if ($validated['type'] === 'text' && $request->hasFile('media')) {
            return response()->json([
                'message' => 'Text Updates cannot contain media.'
            ], 422);
        }

        if (
            in_array($validated['type'], ['image', 'video']) &&
            !$request->hasFile('media')
        ) {
            return response()->json([
                'message' => 'Media is required for image and video Updates.'
            ], 422);
        }

        if ($validated['type'] === 'video') {
            $mime = $request->file('media')->getMimeType();

            if (!str_starts_with($mime, 'video/')) {
                return response()->json([
                    'message' => 'The uploaded file must be a video.'
                ], 422);
            }
        }

        if ($validated['type'] === 'image') {
            $mime = $request->file('media')->getMimeType();

            if (!str_starts_with($mime, 'image/')) {
                return response()->json([
                    'message' => 'The uploaded file must be an image.'
                ], 422);
            }
        }

        $user = $request->user();

        $cloudinaryData = null;

        if ($request->hasFile('media')) {
            $cloudinaryData = $this->cloudinary->upload(
                $request->file('media'),
                $validated['type'] === 'video' ? 'video' : 'image'
            );
        }

        $update = DB::transaction(function () use (
            $validated,
            $user,
            $cloudinaryData,
            $durationHours
        ) {
            return Update::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'type' => $validated['type'],
                'caption' => $validated['caption'] ?? null,

                'cloudinary_public_id' =>
                    $cloudinaryData['public_id'] ?? null,

                'cloudinary_url' =>
                    $cloudinaryData['secure_url'] ?? null,

                'cloudinary_resource_type' =>
                    $cloudinaryData['resource_type'] ?? null,

                'duration_hours' => $durationHours,

                'expires_at' => now()->addHours($durationHours),

                'allow_repost' =>
                    $validated['allow_repost'] ?? true,
            ]);
        });

        // Tag the groups (a failure here never loses the Update itself).
        $tagResult = $this->tagGroups(
            $request,
            $update,
            (array) ($validated['group_ids'] ?? [])
        );

        $update->load('user');

        $update->tagged_groups = $tagResult['tagged'];

        $response = [
            'message' => 'Update created successfully.',
            'update' => $update,
        ];

        if (!empty($tagResult['skipped'])) {
            $response['skipped_group_ids'] = $tagResult['skipped'];
        }

        return response()->json($response, 201);
    }

    /**
     * Get Updates visible to the authenticated user (contacts only —
     * this intentionally excludes the viewer's own Updates; see mine()).
     */
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        $blockedUserIds = DB::table('update_blocked_contacts')
            ->where('blocked_user_id', $userId)
            ->pluck('user_id');

        $contactUserIds = DB::table('contacts')
            ->where('user_id', $userId)
            ->whereNotNull('contact_user_id')
            ->where('is_blocked', false)
            ->pluck('contact_user_id');

        $updates = Update::query()
            ->with('user')
            ->active()
            ->whereIn('user_id', $contactUserIds)
            ->whereNotIn('user_id', $blockedUserIds)
            ->latest()
            ->get();

        $this->attachViewedFlag($updates, $userId);

        return response()->json([
            'updates' => $updates,
        ]);
    }

    /**
     * Get the authenticated user's own active Updates.
     *
     * The "My Update" card in the app needs this — index() deliberately
     * excludes the viewer's own posts, so without this endpoint a freshly
     * posted Update never shows up anywhere for its own author.
     */
    public function mine(Request $request)
    {
        $userId = $request->user()->id;

        $updates = Update::query()
            ->with('user')
            ->active()
            ->where('user_id', $userId)
            // Oldest first: the story viewer plays a person's updates in
            // the order they were posted.
            ->oldest()
            ->get();

        // Private replies: chat messages that carry a preview of one of
        // these Updates.
        $uuids = $updates->pluck('uuid')->all();

        $repliesByUuid = collect();

        if (!empty($uuids)) {
            $repliesByUuid = Message::query()
                // NEW: verified_badge added to the selected columns
                ->with('sender:id,name,avatar_url,verified_badge')
                ->whereIn('update_preview->update_uuid', $uuids)
                ->where('sender_id', '!=', $userId)
                ->where('is_deleted', false)
                // (The status cards posted in groups are not replies.)
                ->where('type', '!=', self::GROUP_STATUS_TYPE)
                ->latest()
                ->get()
                ->groupBy(fn ($m) => $m->update_preview['update_uuid'] ?? '');
        }

        $updates->each(function ($update) use ($repliesByUuid) {
            // You've implicitly "seen" your own Updates.
            $update->viewed_by_me = true;

            $replies = $repliesByUuid->get($update->uuid, collect());

            $update->replies_count = $replies->count();

            $update->private_replies = $replies->take(20)->map(fn ($m) => [
                'message_id' => $m->id,
                'chat_id' => $m->chat_id,
                'body' => $m->body,
                'created_at' => $m->created_at,
                'sender' => [
                    'id' => $m->sender?->id,
                    'name' => $m->sender?->name,
                    'avatar_url' => $m->sender?->avatar_url,
                    // NEW
                    'verified_badge' => (bool) ($m->sender?->verified_badge ?? false),
                ],
            ])->values();
        });

        // The groups each Update is tagged in.
        $this->attachTaggedGroups($updates);

        return response()->json([
            'updates' => $updates,
        ]);
    }

    /**
     * Show a single Update.
     */
    public function show(Request $request, string $uuid)
    {
        $update = Update::with('user')
            ->active()
            ->where('uuid', $uuid)
            ->firstOrFail();

        $this->ensureCanView($request->user()->id, $update);

        return response()->json([
            'update' => $update,
        ]);
    }

    /**
     * Record that the authenticated user viewed an Update.
     */
    public function view(Request $request, string $uuid)
    {
        $update = Update::active()
            ->where('uuid', $uuid)
            ->firstOrFail();

        $this->ensureCanView($request->user()->id, $update);

        UpdateView::updateOrCreate(
            [
                'update_id' => $update->id,
                'viewer_id' => $request->user()->id,
            ],
            [
                'viewed_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Update viewed.',
        ]);
    }

    /**
     * Delete an Update owned by the authenticated user.
     */
    public function destroy(Request $request, string $uuid)
    {
        $update = Update::where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        /*
         * Don't delete Cloudinary media if another active
         * Update is still using the same asset.
         */
        $mediaStillUsed = Update::where(
            'cloudinary_public_id',
            $update->cloudinary_public_id
        )
            ->where('id', '!=', $update->id)
            ->active()
            ->exists();

        if (
            $update->cloudinary_public_id &&
            !$mediaStillUsed
        ) {
            $this->cloudinary->delete(
                $update->cloudinary_public_id,
                $update->cloudinary_resource_type ?? 'image'
            );
        }

        $update->delete();

        return response()->json([
            'message' => 'Update deleted successfully.',
        ]);
    }

    // ============================================================
    // GROUP TAGGING
    // ============================================================

    /**
     * Posts a "group_status" message in every group the user is a member
     * of (from the requested group ids). Groups the user is not in are
     * skipped, never an error.
     *
     * Returns:
     *   tagged  = [{chat_id, name, avatar_url, message_id}, ...]
     *   skipped = [group ids that were not used]
     */
    private function tagGroups(Request $request, Update $update, array $groupIds): array
    {
        $user = $request->user();

        $groupIds = array_values(array_unique(array_map('intval', $groupIds)));
        $groupIds = array_slice($groupIds, 0, self::MAX_TAGGED_GROUPS);

        if (empty($groupIds)) {
            return ['tagged' => [], 'skipped' => []];
        }

        // Only real groups that this user belongs to.
        $chats = Chat::query()
            ->where('type', 'group')
            ->whereIn('id', $groupIds)
            ->whereHas(
                'participants',
                fn ($q) => $q->where('user_id', $user->id)
            )
            ->get()
            ->keyBy('id');

        $preview = $this->buildPreview($update, $user);
        $body = $this->previewBody($update);

        $tagged = [];
        $skipped = [];

        foreach ($groupIds as $chatId) {
            $chat = $chats->get($chatId);

            if (!$chat) {
                $skipped[] = $chatId;
                continue;
            }

            try {
                // forceFill: works even if these columns are not in the
                // Message model's $fillable.
                $message = new Message();

                $message->forceFill([
                    'chat_id' => $chat->id,
                    'sender_id' => $user->id,
                    'body' => $body,
                    'type' => self::GROUP_STATUS_TYPE,
                    'update_preview' => $preview,
                    'is_deleted' => false,
                    'deleted_for_user_ids' => null,
                ])->save();

                $message->load(['sender', 'replyTo.sender', 'sticker']);

                try {
                    broadcast(new NewMessage($message));
                } catch (\Throwable $e) {
                    Log::warning('Tagged status broadcast failed', [
                        'message_id' => $message->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                app()->terminating(function () use ($request, $chat, $message) {
                    try {
                        app(MessageController::class)
                            ->sendPushToOtherParticipants($request, $chat, $message);
                    } catch (\Throwable $e) {
                        Log::warning('Tagged status push failed', [
                            'message_id' => $message->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                });

                $tagged[] = [
                    'chat_id' => (int) $chat->id,
                    'name' => $chat->name,
                    'avatar_url' => $chat->group_avatar,
                    'message_id' => (int) $message->id,
                ];
            } catch (\Throwable $e) {
                Log::warning('Could not tag a group in an Update', [
                    'update_id' => $update->id,
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);

                $skipped[] = $chatId;
            }
        }

        return ['tagged' => $tagged, 'skipped' => $skipped];
    }

    /**
     * The small preview stored on the message (the app draws the status
     * card from it, and opens the Update with update_uuid).
     */
    private function buildPreview(Update $update, $owner): array
    {
        return [
            'update_uuid' => $update->uuid,
            'owner_id' => (int) $owner->id,
            'owner_name' => $owner->name,
            'type' => $update->type,
            'caption' => $update->caption
                ? Str::limit(trim($update->caption), 140)
                : null,
            'thumb_url' => $this->thumbUrlFor($update),
        ];
    }

    /**
     * The text of the message (the chats list shows it as the last
     * message of the group).
     */
    private function previewBody(Update $update): string
    {
        $caption = trim((string) $update->caption);

        if ($caption !== '') {
            return 'Status: ' . Str::limit($caption, 60);
        }

        return match ($update->type) {
            'video' => 'Video status',
            'image' => 'Photo status',
            default => 'Status',
        };
    }

    /**
     * A picture for the status card. Images use their own url. For videos,
     * Cloudinary makes a still picture when the extension is .jpg.
     */
    private function thumbUrlFor(Update $update): ?string
    {
        $url = $update->cloudinary_url;

        if (!$url) {
            return null;
        }

        if ($update->type === 'video') {
            return preg_replace('/\.[A-Za-z0-9]+(\?.*)?$/', '.jpg', $url);
        }

        return $url;
    }

    /**
     * Sets `tagged_groups` on every Update of the collection, using the
     * "group_status" messages that were posted for it.
     */
    private function attachTaggedGroups($updates): void
    {
        $uuids = $updates->pluck('uuid')->all();

        $byUuid = collect();
        $chats = collect();

        if (!empty($uuids)) {
            $messages = Message::query()
                ->where('type', self::GROUP_STATUS_TYPE)
                ->where('is_deleted', false)
                ->whereIn('update_preview->update_uuid', $uuids)
                ->get(['id', 'chat_id', 'update_preview']);

            $chats = Chat::query()
                ->whereIn('id', $messages->pluck('chat_id')->unique()->all())
                ->get()
                ->keyBy('id');

            $byUuid = $messages->groupBy(
                fn ($m) => $m->update_preview['update_uuid'] ?? ''
            );
        }

        $updates->each(function ($update) use ($byUuid, $chats) {
            $messages = $byUuid->get($update->uuid, collect());

            $update->tagged_groups = $messages
                ->map(function ($m) use ($chats) {
                    $chat = $chats->get($m->chat_id);

                    if (!$chat) {
                        return null;
                    }

                    return [
                        'chat_id' => (int) $chat->id,
                        'name' => $chat->name,
                        'avatar_url' => $chat->group_avatar,
                        'message_id' => (int) $m->id,
                    ];
                })
                ->filter()
                ->values();
        });
    }

    /**
     * True when this Update was tagged in a group that the viewer is a
     * member of (so group members can open it even if they are not
     * contacts of the owner).
     */
    private function isTaggedInSharedGroup(int $viewerId, Update $update): bool
    {
        $chatIds = Message::query()
            ->where('type', self::GROUP_STATUS_TYPE)
            ->where('is_deleted', false)
            ->where('update_preview->update_uuid', $update->uuid)
            ->pluck('chat_id')
            ->unique()
            ->all();

        if (empty($chatIds)) {
            return false;
        }

        return Chat::query()
            ->where('type', 'group')
            ->whereIn('id', $chatIds)
            ->whereHas(
                'participants',
                fn ($q) => $q->where('user_id', $viewerId)
            )
            ->exists();
    }

    /**
     * Make sure a user is allowed to see an Update.
     */
    private function ensureCanView(int $viewerId, Update $update): void
    {
        // Owner can always see their own Update.
        if ($update->user_id === $viewerId) {
            return;
        }

        // Check contact relationship.
        $isContact = DB::table('contacts')
            ->where('user_id', $viewerId)
            ->where('contact_user_id', $update->user_id)
            ->where('is_blocked', false)
            ->exists();

        // Not a contact: still allowed when the owner tagged a group that
        // the viewer is in.
        if (!$isContact && !$this->isTaggedInSharedGroup($viewerId, $update)) {
            abort(403, 'You cannot view this Update.');
        }

        // Check Update-specific blocklist (always respected).
        $isBlocked = DB::table('update_blocked_contacts')
            ->where('user_id', $update->user_id)
            ->where('blocked_user_id', $viewerId)
            ->exists();

        if ($isBlocked) {
            abort(403, 'You cannot view this Update.');
        }
    }

    /**
     * Tags each Update in the collection with whether the given viewer
     * has already seen it, so the app can render the story ring correctly
     * without an extra request per Update.
     */
    private function attachViewedFlag($updates, int $viewerId): void
    {
        $viewedIds = UpdateView::where('viewer_id', $viewerId)
            ->whereIn('update_id', $updates->pluck('id'))
            ->pluck('update_id')
            ->all();

        $updates->each(function ($update) use ($viewedIds) {
            $update->viewed_by_me = in_array($update->id, $viewedIds);
        });
    }

    /**
     * Repost an Update.
     */
    public function repost(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'duration_hours' => 'required|integer|in:24,48',
            'caption' => 'nullable|string|max:5000',
        ]);

        // Cast: validated values stay strings; Carbon 3 needs int|float.
        $durationHours = (int) $validated['duration_hours'];

        $user = $request->user();

        $originalUpdate = Update::active()
            ->where('uuid', $uuid)
            ->firstOrFail();

        // You cannot repost your own Update.
        if ($originalUpdate->user_id === $user->id) {
            return response()->json([
                'message' => 'You cannot repost your own Update.',
            ], 422);
        }

        // Check whether this user can actually see it.
        $this->ensureCanView($user->id, $originalUpdate);

        // Check owner's global repost setting.
        $allowGlobalReposts = $originalUpdate->user
            ->updateSettings()
            ->value('allow_reposts');

        // If no settings row exists, default to allowing reposts.
        if ($allowGlobalReposts === null) {
            $allowGlobalReposts = true;
        }

        if (!$allowGlobalReposts || !$originalUpdate->allow_repost) {
            return response()->json([
                'message' => 'This Update cannot be reposted.',
            ], 403);
        }

        // Prevent duplicate reposts of the same Update.
        $alreadyReposted = \App\Models\UpdateRepost::where(
            'update_id',
            $originalUpdate->id
        )
            ->where('user_id', $user->id)
            ->exists();

        if ($alreadyReposted) {
            return response()->json([
                'message' => 'You have already reposted this Update.',
            ], 409);
        }

        $repostedUpdate = DB::transaction(function () use (
            $originalUpdate,
            $user,
            $validated,
            $durationHours
        ) {
            $newUpdate = Update::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,

                'type' => $originalUpdate->type,

                // User can add their own caption.
                'caption' => $validated['caption'] ?? null,

                // IMPORTANT:
                // We reuse the same Cloudinary asset.
                'cloudinary_public_id' =>
                    $originalUpdate->cloudinary_public_id,

                'cloudinary_url' =>
                    $originalUpdate->cloudinary_url,

                'cloudinary_resource_type' =>
                    $originalUpdate->cloudinary_resource_type,

                'duration_hours' => $durationHours,

                // Repost gets its OWN lifetime.
                'expires_at' => now()->addHours($durationHours),

                // Eze can decide whether people can repost Eze's
                // repost.
                'allow_repost' => true,

                'original_update_id' => $originalUpdate->id,
            ]);

            \App\Models\UpdateRepost::create([
                'update_id' => $originalUpdate->id,
                'user_id' => $user->id,
            ]);

            return $newUpdate;
        });

        return response()->json([
            'message' => 'Update reposted successfully.',
            'update' => $repostedUpdate->load('user'),
        ], 201);
    }

    /**
     * Remove your repost of an Update.
     */
    public function removeRepost(Request $request, string $uuid)
    {
        $user = $request->user();

        $repost = Update::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->whereNotNull('original_update_id')
            ->firstOrFail();

        $repost->delete();

        \App\Models\UpdateRepost::where('update_id', $repost->original_update_id)
            ->where('user_id', $user->id)
            ->delete();

        return response()->json([
            'message' => 'Repost removed successfully.',
        ]);
    }


    /**
     * Get the authenticated user's Update settings.
     */
    public function settings(Request $request)
    {
        $settings = \App\Models\UpdateSetting::firstOrCreate(
            [
                'user_id' => $request->user()->id,
            ],
            [
                'allow_reposts' => true,
            ]
        );

        return response()->json([
            'settings' => $settings,
        ]);
    }

    /**
     * Update the authenticated user's Update settings.
     */
    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'allow_reposts' => 'required|boolean',
        ]);

        $settings = \App\Models\UpdateSetting::updateOrCreate(
            [
                'user_id' => $request->user()->id,
            ],
            [
                'allow_reposts' => $validated['allow_reposts'],
            ]
        );

        return response()->json([
            'message' => 'Update settings saved successfully.',
            'settings' => $settings,
        ]);
    }


    /**
     * Get users blocked from seeing my Updates.
     */
    public function blockedContacts(Request $request)
    {
        $blocked = \App\Models\UpdateBlockedContact::with('blockedUser:id,name,username,avatar_url')
            ->where('user_id', $request->user()->id)
            ->get();

        return response()->json([
            'blocked_contacts' => $blocked,
        ]);
    }

    /**
     * Block a contact from seeing my Updates.
     */
    public function blockContact(Request $request, int $userId)
    {
        $user = $request->user();

        if ($user->id === $userId) {
            return response()->json([
                'message' => 'You cannot block yourself from your own Updates.',
            ], 422);
        }

        // Make sure this person is actually a contact.
        $isContact = DB::table('contacts')
            ->where('user_id', $user->id)
            ->where('contact_user_id', $userId)
            ->where('is_blocked', false)
            ->exists();

        if (!$isContact) {
            return response()->json([
                'message' => 'This user is not one of your contacts.',
            ], 422);
        }

        $blocked = \App\Models\UpdateBlockedContact::firstOrCreate([
            'user_id' => $user->id,
            'blocked_user_id' => $userId,
        ]);

        return response()->json([
            'message' => 'Contact blocked from your Updates.',
            'blocked_contact' => $blocked->load(
                'blockedUser:id,name,username,avatar_url'
            ),
        ], 201);
    }

    /**
     * Remove a contact from the Update blocklist.
     */
    public function unblockContact(Request $request, int $userId)
    {
        $deleted = \App\Models\UpdateBlockedContact::where(
            'user_id',
            $request->user()->id
        )
            ->where('blocked_user_id', $userId)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'message' => 'Contact was not on your Update blocklist.',
            ], 404);
        }

        return response()->json([
            'message' => 'Contact can now see your Updates.',
        ]);
    }


}