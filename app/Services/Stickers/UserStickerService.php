<?php

namespace App\Services\Stickers;

use App\Models\Sticker;
use App\Models\StickerPack;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Lets a user make their own stickers. They live in a private "My Stickers"
 * pack that only that user can see and send from.
 */
class UserStickerService
{
    /** The user's personal pack (created and installed on first use). */
    public function personalPack(User $user): StickerPack
    {
        $pack = StickerPack::firstOrCreate(
            ['owner_id' => $user->id],
            [
                'name' => 'My Stickers',
                'slug' => 'my-stickers-' . $user->id,
                'source' => 'user',
                'is_official' => false,
                'is_active' => true,
            ]
        );

        if (!$user->stickerPacks()->where('sticker_packs.id', $pack->id)->exists()) {
            // position 0 = first tab in the picker
            $user->stickerPacks()->attach($pack->id, ['position' => 0]);
        }

        return $pack;
    }

    public function create(User $user, UploadedFile $file, ?string $emoji = null): Sticker
    {
        $pack = $this->personalPack($user);
        $limit = (int) config('stickers.user_sticker_limit', 30);

        if ($pack->stickers()->count() >= $limit) {
            throw new RuntimeException("You can keep up to {$limit} stickers. Delete one to add another.");
        }

        [$binary, $format] = $this->render($file->getRealPath());
        $checksum = sha1($binary);

        // Same picture already in the pack? Reuse it (or bring it back).
        $existing = Sticker::where('sticker_pack_id', $pack->id)->where('checksum', $checksum)->first();

        if ($existing) {
            if ($existing->is_removed) {
                $existing->update([
                    'is_removed' => false,
                    'position' => (int) Sticker::where('sticker_pack_id', $pack->id)->max('position') + 1,
                ]);
                $this->refreshPack($pack);
            }

            return $existing;
        }

        $path = trim(config('stickers.directory'), '/') . "/{$pack->uuid}/{$checksum}.{$format}";
        Storage::disk(config('stickers.disk'))->put($path, $binary);

        return DB::transaction(function () use ($pack, $path, $format, $binary, $checksum, $emoji) {
            $sticker = Sticker::create([
                'sticker_pack_id' => $pack->id,
                'file_path' => $path,
                'format' => $format,
                'is_animated' => false,
                'width' => 512,
                'height' => 512,
                'file_size' => strlen($binary),
                'checksum' => $checksum,
                'emoji' => $emoji ?: null,
                'position' => (int) Sticker::where('sticker_pack_id', $pack->id)->max('position') + 1,
            ]);

            $this->refreshPack($pack);

            return $sticker;
        });
    }

    /** Recount the pack and make sure its cover is a sticker that still exists. */
    public function refreshPack(StickerPack $pack): void
    {
        $pack->stickers_count = $pack->stickers()->count();

        $coverOk = $pack->cover_sticker_id
            && Sticker::whereKey($pack->cover_sticker_id)->where('is_removed', false)->exists();

        if (!$coverOk) {
            $pack->cover_sticker_id = optional($pack->stickers()->first())->id;
        }

        $pack->save();
    }

    /**
     * Centre-crop to a square, resize to 512x512 and encode as webp
     * (png if this server's PHP has no webp support). Keeps transparency.
     *
     * @return array{0: string, 1: string} [binary, format]
     */
    protected function render(string $path): array
    {
        $info = @getimagesize($path);

        $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];

        if (!$info || !in_array($info[2], $allowed, true)) {
            throw new RuntimeException('Please choose a JPG, PNG, WEBP or GIF image.');
        }

        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException('Image processing is not available on this server.');
        }

        $src = @imagecreatefromstring((string) file_get_contents($path));

        if (!$src) {
            throw new RuntimeException('Could not read that image.');
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $x = intdiv($w - $side, 2);
        $y = intdiv($h - $side, 2);
        $size = 512;

        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $src, 0, 0, $x, $y, $size, $size, $side, $side);

        ob_start();

        if (function_exists('imagewebp')) {
            imagewebp($canvas, null, 85);
            $format = 'webp';
        } else {
            imagepng($canvas, null, 8);
            $format = 'png';
        }

        $binary = (string) ob_get_clean();

        imagedestroy($src);
        imagedestroy($canvas);

        return [$binary, $format];
    }
}