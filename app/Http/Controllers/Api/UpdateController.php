<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Update;
use App\Models\UpdateView;
use App\Services\CloudinaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UpdateController extends Controller
{
    public function __construct(
        protected CloudinaryService $cloudinary
    ) {
    }

    /**
     * Create a new Update.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:text,image,video',
            'caption' => 'nullable|string|max:5000',
            'duration_hours' => 'required|integer|in:24,48',
            'allow_repost' => 'nullable|boolean',
            'media' => 'required_if:type,image,video|file|max:102400',
        ]);

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
            $cloudinaryData
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

                'duration_hours' => $validated['duration_hours'],

                'expires_at' => now()->addHours(
                    $validated['duration_hours']
                ),

                'allow_repost' =>
                    $validated['allow_repost'] ?? true,
            ]);
        });

        return response()->json([
            'message' => 'Update created successfully.',
            'update' => $update->load('user'),
        ], 201);
    }

    /**
     * Get Updates visible to the authenticated user.
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

        if (!$isContact) {
            abort(403, 'You cannot view this Update.');
        }

        // Check Update-specific blocklist.
        $isBlocked = DB::table('update_blocked_contacts')
            ->where('user_id', $update->user_id)
            ->where('blocked_user_id', $viewerId)
            ->exists();

        if ($isBlocked) {
            abort(403, 'You cannot view this Update.');
        }
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
            $validated
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

                'duration_hours' => $validated['duration_hours'],

                // Repost gets its OWN lifetime.
                'expires_at' => now()->addHours(
                    $validated['duration_hours']
                ),

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

