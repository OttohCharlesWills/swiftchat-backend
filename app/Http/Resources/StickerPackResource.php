<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StickerPackResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'author' => $this->author,
            'description' => $this->description,
            'is_official' => $this->is_official,
            'is_animated' => $this->is_animated,
            'stickers_count' => $this->stickers_count,
            'installed' => (bool) $this->resource->getAttribute('installed'),
            'cover_url' => $this->whenLoaded('cover', fn () => optional($this->cover)->url),
            'stickers' => StickerResource::collection($this->whenLoaded('stickers')),
        ];
    }
}