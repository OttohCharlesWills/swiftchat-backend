<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StickerResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->uuid,
            'url' => $this->url,
            'format' => $this->format,
            'is_animated' => $this->is_animated,
            'width' => $this->width,
            'height' => $this->height,
            'emoji' => $this->emoji,
            'keywords' => $this->keywords ?? [],
        ];
    }
}