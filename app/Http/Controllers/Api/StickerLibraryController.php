<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StickerCollectionResource;
use App\Http\Resources\StickerResource;
use App\Models\Sticker;
use App\Models\StickerCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Favorites (unlimited) and collections (named, limited) of saved stickers.
 */
class StickerLibraryController extends Controller
{
    // ------------------------------------------------------------
    // FAVORITES
    // ------------------------------------------------------------

    /** GET /stickers/favorites */
    public function favorites(Request $request)
    {
        $stickers = Sticker::query()
            ->join('user_favorite_stickers as f', 'f.sticker_id', '=', 'stickers.id')
            ->where('f.user_id', $request->user()->id)
            ->orderByDesc('f.created_at')
            ->select('stickers.*')
            ->get();

        return StickerResource::collection($stickers);
    }

    /** POST /stickers/favorites/{sticker} */
    public function addFavorite(Request $request, Sticker $sticker)
    {
        $this->authorizeSticker($request, $sticker);

        DB::table('user_favorite_stickers')->insertOrIgnore([
            'user_id' => $request->user()->id,
            'sticker_id' => $sticker->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['favorite' => true]);
    }

    /** DELETE /stickers/favorites/{sticker} */
    public function removeFavorite(Request $request, Sticker $sticker)
    {
        DB::table('user_favorite_stickers')
            ->where('user_id', $request->user()->id)
            ->where('sticker_id', $sticker->id)
            ->delete();

        return response()->json(['favorite' => false]);
    }

    // ------------------------------------------------------------
    // WHAT THE "SAVE STICKER" SHEET NEEDS (one call)
    // ------------------------------------------------------------

    /**
     * GET /stickers/library/{sticker}
     * { favorite: bool, collections: [{ id, name, stickers_count, limit, has_sticker }] }
     */
    public function library(Request $request, Sticker $sticker)
    {
        $this->authorizeSticker($request, $sticker);

        $userId = (int) $request->user()->id;

        $favorite = DB::table('user_favorite_stickers')
            ->where('user_id', $userId)
            ->where('sticker_id', $sticker->id)
            ->exists();

        $collections = StickerCollection::where('user_id', $userId)
            ->withCount('stickers')
            ->orderBy('created_at')
            ->get();

        $containing = DB::table('sticker_collection_items')
            ->whereIn('collection_id', $collections->pluck('id'))
            ->where('sticker_id', $sticker->id)
            ->pluck('collection_id');

        $collections->each(fn ($c) => $c->setAttribute('has_sticker', $containing->contains($c->id)));

        return response()->json([
            'favorite' => $favorite,
            'collections' => StickerCollectionResource::collection($collections)->resolve($request),
        ]);
    }

    // ------------------------------------------------------------
    // COLLECTIONS
    // ------------------------------------------------------------

    /** GET /stickers/collections?stickers=1 */
    public function collections(Request $request)
    {
        $collections = StickerCollection::where('user_id', $request->user()->id)
            ->withCount('stickers')
            ->orderBy('created_at')
            ->get();

        if ($request->boolean('stickers')) {
            $collections->load('stickers');
        }

        return StickerCollectionResource::collection($collections);
    }

    /** POST /stickers/collections  { name } */
    public function createCollection(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:40',
        ]);

        $userId = (int) $request->user()->id;
        $name = trim($data['name']);

        if ($name === '') {
            return response()->json(['message' => 'Give the collection a name.'], 422);
        }

        $max = (int) config('stickers.max_collections', 20);

        if (StickerCollection::where('user_id', $userId)->count() >= $max) {
            return response()->json(['message' => "You can have up to {$max} collections."], 422);
        }

        $exists = StickerCollection::where('user_id', $userId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'You already have a collection with that name.'], 422);
        }

        $collection = StickerCollection::create(['user_id' => $userId, 'name' => $name]);
        $collection->setAttribute('stickers_count', 0);

        return (new StickerCollectionResource($collection))->response()->setStatusCode(201);
    }

    /** DELETE /stickers/collections/{collection} */
    public function deleteCollection(Request $request, StickerCollection $collection)
    {
        $this->authorizeCollection($request, $collection);

        $collection->delete();

        return response()->json(['deleted' => true]);
    }

    /** POST /stickers/collections/{collection}/items/{sticker} */
    public function addToCollection(Request $request, StickerCollection $collection, Sticker $sticker)
    {
        $this->authorizeCollection($request, $collection);
        $this->authorizeSticker($request, $sticker);

        $limit = (int) config('stickers.collection_sticker_limit', 30);

        $already = DB::table('sticker_collection_items')
            ->where('collection_id', $collection->id)
            ->where('sticker_id', $sticker->id)
            ->exists();

        if (!$already) {
            $count = DB::table('sticker_collection_items')->where('collection_id', $collection->id)->count();

            if ($count >= $limit) {
                return response()->json([
                    'message' => "\"{$collection->name}\" is full ({$count}/{$limit}). Remove a sticker or use another collection.",
                ], 422);
            }

            DB::table('sticker_collection_items')->insertOrIgnore([
                'collection_id' => $collection->id,
                'sticker_id' => $sticker->id,
                'position' => (int) DB::table('sticker_collection_items')->where('collection_id', $collection->id)->max('position') + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $collection->loadCount('stickers');
        $collection->setAttribute('has_sticker', true);

        return new StickerCollectionResource($collection);
    }

    /** DELETE /stickers/collections/{collection}/items/{sticker} */
    public function removeFromCollection(Request $request, StickerCollection $collection, Sticker $sticker)
    {
        $this->authorizeCollection($request, $collection);

        DB::table('sticker_collection_items')
            ->where('collection_id', $collection->id)
            ->where('sticker_id', $sticker->id)
            ->delete();

        $collection->loadCount('stickers');

        return new StickerCollectionResource($collection);
    }

    // ------------------------------------------------------------
    // PERMISSIONS
    // ------------------------------------------------------------

    protected function authorizeCollection(Request $request, StickerCollection $collection): void
    {
        abort_unless((int) $collection->user_id === (int) $request->user()->id, 403, 'Not your collection.');
    }

    /**
     * A user may save a sticker if they can already use it (public / their own /
     * already saved) OR it was sent in a chat they are part of. That last rule is
     * what lets you save a sticker a friend made privately and sent you.
     */
    protected function authorizeSticker(Request $request, Sticker $sticker): void
    {
        $userId = (int) $request->user()->id;

        if (Sticker::whereKey($sticker->id)->usableBy($userId)->exists()) {
            return;
        }

        $receivedInChat = DB::table('messages')
            ->join('chat_participants', 'chat_participants.chat_id', '=', 'messages.chat_id')
            ->where('messages.sticker_id', $sticker->id)
            ->where('chat_participants.user_id', $userId)
            ->exists();

        abort_unless($receivedInChat, 404, 'Sticker not found.');
    }
}