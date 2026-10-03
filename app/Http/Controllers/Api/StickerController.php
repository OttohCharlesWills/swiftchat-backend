<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StickerPackResource;
use App\Http\Resources\StickerResource;
use App\Models\Sticker;
use App\Models\StickerPack;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StickerController extends Controller
{
    /** GET /stickers/packs?search= — the sticker store */
    public function packs(Request $request)
    {
        $query = StickerPack::active()->with('cover')->where('stickers_count', '>', 0);

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
        abort_unless($pack->is_active, 404);

        $pack->load(['cover', 'stickers']);
        $this->markInstalled($request, collect([$pack]));

        return new StickerPackResource($pack);
    }

    /** GET /stickers/my-packs — what the sticker picker shows */
    public function myPacks(Request $request)
    {
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
        abort_unless($pack->is_active, 404);

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
        $request->user()->stickerPacks()->detach($pack->id);

        return response()->json(['installed' => false]);
    }

    /** GET /stickers/recent */
    public function recent(Request $request)
    {
        $stickers = $request->user()->recentStickers()
            ->whereHas('pack', fn ($q) => $q->active())
            ->limit(config('stickers.recent_limit'))
            ->get();

        return StickerResource::collection($stickers);
    }

    /** GET /stickers/search?q=😂 or ?q=laugh */
    public function search(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        abort_if($q === '', 422, 'Search term required.');

        $stickers = Sticker::query()
            ->whereHas('pack', fn ($p) => $p->active())
            ->where(function ($w) use ($q) {
                $w->where('emoji', $q)->orWhereJsonContains('keywords', mb_strtolower($q));
            })
            ->limit(50)
            ->get();

        return StickerResource::collection($stickers);
    }

    protected function markInstalled(Request $request, $packs): void
    {
        $installed = $request->user()->stickerPacks()->pluck('sticker_packs.id');

        $packs->each(fn ($p) => $p->setAttribute('installed', $installed->contains($p->id)));
    }
}