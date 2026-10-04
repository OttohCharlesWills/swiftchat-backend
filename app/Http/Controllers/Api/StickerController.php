<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StickerPackResource;
use App\Http\Resources\StickerResource;
use App\Models\Message;
use App\Models\Sticker;
use App\Models\StickerPack;
use App\Services\Stickers\UserStickerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StickerController extends Controller
{
    /** GET /stickers/packs?search= — the sticker store (public packs only) */
    public function packs(Request $request)
    {
        $query = StickerPack::active()
            ->whereNull('owner_id')
            ->with('cover')
            ->where('stickers_count', '>', 0);

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where('name', 'like', "%{$search}%");
        }

        $packs = $query->orderByDesc('is_official')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(20);

        $this->markInstalled($request, $packs->getCollection());

        return StickerPackResource::collection($packs);
    }

    /** GET /stickers/packs/{pack} */
    public function show(Request $request, StickerPack $pack)
    {
        abort_unless(
            $pack->is_active && ($pack->owner_id === null || (int) $pack->owner_id === (int) $request->user()->id),
            404
        );

        $pack->load(['cover', 'stickers']);
        $this->markInstalled($request, collect([$pack]));

        return new StickerPackResource($pack);
    }

    /** GET /stickers/my-packs — what the sticker picker shows */
    public function myPacks(Request $request, UserStickerService $userStickers)
    {
        // Makes sure the user's own "My Stickers" pack exists and is installed.
        $userStickers->personalPack($request->user());

        $packs = $request->user()->stickerPacks()
            ->active()
            ->with(['cover', 'stickers'])
            ->get();

        $packs->each(fn ($p) => $p->setAttribute('installed', true));

        return StickerPackResource::collection($packs);
    }

    /** POST /stickers/packs/{pack}/install */
    public function install(Request $request, StickerPack $pack)
    {
        // Personal packs can't be installed by anyone else.
        abort_unless($pack->is_active && $pack->owner_id === null, 404);

        $user = $request->user();

        $already = $user->stickerPacks()->where('sticker_packs.id', $pack->id)->exists();

        if (!$already) {
            $position = (int) DB::table('user_sticker_packs')->where('user_id', $user->id)->max('position');
            $user->stickerPacks()->attach($pack->id, ['position' => $position + 1]);
        }

        return response()->json(['installed' => true]);
    }

    /** DELETE /stickers/packs/{pack}/install */
    public function uninstall(Request $request, StickerPack $pack)
    {
        // "My Stickers" is always there.
        abort_if($pack->owner_id !== null, 422, 'You cannot remove your own sticker pack.');

        $request->user()->stickerPacks()->detach($pack->id);

        return response()->json(['installed' => false]);
    }

    /** GET /stickers/recent */
    public function recent(Request $request)
    {
        $userId = (int) $request->user()->id;

        $stickers = $request->user()->recentStickers()
            ->available()
            ->whereHas('pack', fn ($q) => $q->active()->visibleTo($userId))
            ->limit(config('stickers.recent_limit'))
            ->get();

        return StickerResource::collection($stickers);
    }

    /** GET /stickers/search?q=😂 or ?q=laugh */
    public function search(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        abort_if($q === '', 422, 'Search term required.');

        $userId = (int) $request->user()->id;

        $stickers = Sticker::query()
            ->available()
            ->whereHas('pack', fn ($p) => $p->active()->visibleTo($userId))
            ->where(function ($w) use ($q) {
                $w->where('emoji', $q)->orWhereJsonContains('keywords', mb_strtolower($q));
            })
            ->limit(50)
            ->get();

        return StickerResource::collection($stickers);
    }

    // ------------------------------------------------------------
    // MY STICKERS (user-made, private)
    // ------------------------------------------------------------

    /** POST /stickers/mine  (multipart: file, emoji?) */
    public function storeMine(Request $request, UserStickerService $userStickers)
    {
        $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,gif|max:8192',
            'emoji' => 'nullable|string|max:16',
        ]);

        try {
            $sticker = $userStickers->create(
                $request->user(),
                $request->file('file'),
                $request->input('emoji')
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new StickerResource($sticker))->response()->setStatusCode(201);
    }

    /** DELETE /stickers/mine/{sticker} */
    public function destroyMine(Request $request, Sticker $sticker, UserStickerService $userStickers)
    {
        $pack = $sticker->pack;

        abort_unless(
            $pack && (int) $pack->owner_id === (int) $request->user()->id,
            403,
            'This is not your sticker.'
        );

        if (Message::where('sticker_id', $sticker->id)->exists()) {
            // Already sent in chats: hide it so old messages keep showing it.
            $sticker->update(['is_removed' => true]);
            DB::table('user_recent_stickers')->where('sticker_id', $sticker->id)->delete();
        } else {
            $sticker->delete();
        }

        $userStickers->refreshPack($pack);

        return response()->json(['deleted' => true]);
    }

    protected function markInstalled(Request $request, $packs): void
    {
        $installed = $request->user()->stickerPacks()->pluck('sticker_packs.id');

        $packs->each(fn ($p) => $p->setAttribute('installed', $installed->contains($p->id)));
    }
}