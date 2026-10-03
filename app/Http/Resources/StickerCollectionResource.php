<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StickerCollectionResource extends JsonResource
{
    public function toArray($request): array
    {
        $count = $this->resource->getAttribute('stickers_count');

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'stickers_count' => (int) ($count ?? $this->stickers()->count()),
            'limit' => (int) config('stickers.collection_sticker_limit', 30),
            // set by the library endpoint: is the sticker being saved already in this collection?
            'has_sticker' => (bool) $this->resource->getAttribute('has_sticker'),
            'stickers' => StickerResource::collection($this->whenLoaded('stickers')),
        ];
    }
}