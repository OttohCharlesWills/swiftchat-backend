<?php

namespace App\Services;

use App\Models\StickerPack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Imports sticker packs from:
 *  - a folder of images                       (one pack)
 *  - a folder of folders                      (one pack per subfolder)
 *  - a .zip / .wastickers archive             (same layouts as above)
 *
 * Optional metadata, per pack folder:
 *  - pack.json  { "name": "", "author": "", "description": "",
 *                 "stickers": [ { "file": "1.webp", "emoji": "😂", "keywords": ["lol"] } ] }
 *  - title.txt / author.txt   (WhatsApp-style packs)
 *
 * Re-importing the same pack (same slug) is safe: files are de-duplicated by sha1.
 */
class StickerImporter
{
    protected array $warnings = [];

    public function import(string $path, array $options = []): array
    {
        $this->warnings = [];

        if (!file_exists($path)) {
            throw new RuntimeException("Path not found: {$path}");
        }

        $packs = is_dir($path)
            ? $this->importFolder($path, $options)
            : $this->importArchive($path, $options);

        return ['packs' => $packs, 'warnings' => $this->warnings];
    }

    protected function importArchive(string $path, array $options): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open archive (is it a valid zip?).');
        }

        if ($zip->numFiles > config('stickers.max_archive_files')) {
            $zip->close();
            throw new RuntimeException('Archive contains too many files.');
        }

        $tmp = storage_path('app/tmp/sticker-import-' . Str::random(10));
        File::ensureDirectoryExists($tmp);

        $options['root'] = $tmp;
        $options['fallback_name'] = $options['fallback_name'] ?? pathinfo($path, PATHINFO_FILENAME);

        try {
            $total = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = str_replace('\\', '/', $stat['name']);

                // skip folders, mac junk, and anything trying to escape the temp dir
                if (str_ends_with($name, '/') || str_starts_with($name, '__MACOSX')
                    || str_starts_with($name, '/') || str_contains($name, '..')) {
                    continue;
                }

                $total += $stat['size'];
                if ($total > config('stickers.max_archive_bytes')) {
                    throw new RuntimeException('Archive is too large when extracted.');
                }

                $dest = $tmp . '/' . $name;
                File::ensureDirectoryExists(dirname($dest));

                $in = $zip->getStream($stat['name']);
                $out = fopen($dest, 'wb');
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
            }

            return $this->importFolder($tmp, $options);
        } finally {
            $zip->close();
            File::deleteDirectory($tmp);
        }
    }

    protected function importFolder(string $dir, array $options): array
    {
        if ($this->looksLikePack($dir)) {
            return [$this->importPack($dir, $options)];
        }

        $results = [];
        foreach (File::directories($dir) as $sub) {
            if (basename($sub) === '__MACOSX') {
                continue;
            }
            $results = array_merge($results, $this->importFolder($sub, $options));
        }

        return $results;
    }

    protected function looksLikePack(string $dir): bool
    {
        return is_file("$dir/pack.json")
            || is_file("$dir/title.txt")
            || count($this->imageFiles($dir)) > 0;
    }

    protected function imageFiles(string $dir): array
    {
        $allowed = config('stickers.allowed_extensions');
        $ignored = ['pack.json', 'contents.json', 'manifest.json'];
        $files = [];

        foreach (scandir($dir) as $entry) {
            $full = "$dir/$entry";

            if (!is_file($full)) continue;
            if (in_array(strtolower($entry), $ignored, true)) continue;
            if (str_starts_with(strtolower($entry), 'tray')) continue; // WhatsApp tray icon
            if (!in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), $allowed, true)) continue;

            $files[] = $full;
        }

        natcasesort($files);

        return array_values($files);
    }

    protected function readMeta(string $dir): array
    {
        $meta = ['name' => null, 'author' => null, 'description' => null, 'stickers' => [], 'emojis' => []];

        if (is_file("$dir/pack.json")) {
            $json = json_decode(file_get_contents("$dir/pack.json"), true);
            if (is_array($json)) {
                $meta = array_merge($meta, array_intersect_key($json, $meta));
            }
        }

        if (!$meta['name'] && is_file("$dir/title.txt")) {
            $meta['name'] = trim(file_get_contents("$dir/title.txt"));
        }
        if (!$meta['author'] && is_file("$dir/author.txt")) {
            $meta['author'] = trim(file_get_contents("$dir/author.txt"));
        }

        return $meta;
    }

    protected function importPack(string $dir, array $options): array
    {
        $meta = $this->readMeta($dir);

        $fallback = (($options['root'] ?? null) === $dir)
            ? ($options['fallback_name'] ?? 'Sticker Pack')
            : Str::headline(basename($dir));

        $name = Str::limit(trim($meta['name'] ?: $fallback), 120, '');
        $slug = Str::slug($name) ?: 'pack-' . substr(md5($name), 0, 8);

        $files = $this->imageFiles($dir);

        if (!$files) {
            $this->warnings[] = "[{$name}] no sticker files found, skipped.";
            return ['name' => $name, 'slug' => $slug, 'added' => 0, 'updated' => 0, 'skipped' => 0, 'total' => 0];
        }

        $stickerMeta = [];
        $order = [];
        foreach ((array) $meta['stickers'] as $idx => $s) {
            $key = basename($s['file'] ?? '');
            if ($key !== '') {
                $stickerMeta[$key] = $s;
                $order[$key] = $idx;
            }
        }

        if ($order) {
            usort($files, fn ($a, $b) => ($order[basename($a)] ?? PHP_INT_MAX) <=> ($order[basename($b)] ?? PHP_INT_MAX));
        }

        $disk = Storage::disk(config('stickers.disk'));
        $maxSize = config('stickers.max_file_size');
        $added = $updated = $skipped = 0;

        $pack = DB::transaction(function () use (
            $meta, $name, $slug, $files, $stickerMeta, $options, $disk, $maxSize, &$added, &$updated, &$skipped
        ) {
            $pack = StickerPack::withTrashed()->firstOrNew(['slug' => $slug]);

            $pack->fill([
                'name' => $name,
                'author' => $meta['author'],
                'description' => $meta['description'],
                'source' => $options['source'] ?? 'import',
                'is_official' => $options['official'] ?? (bool) $pack->is_official,
                'is_active' => true,
            ])->save();

            if ($pack->trashed()) {
                $pack->restore();
            }

            foreach ($files as $i => $file) {
                $base = basename($file);
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

                if (filesize($file) > $maxSize) {
                    $this->warnings[] = "[{$name}] {$base} is larger than the allowed size, skipped.";
                    $skipped++;
                    continue;
                }

                $info = $this->inspect($file, $ext);
                if (!$info) {
                    $this->warnings[] = "[{$name}] {$base} is not a valid sticker file, skipped.";
                    $skipped++;
                    continue;
                }

                $checksum = sha1_file($file);
                $m = $stickerMeta[$base] ?? [];
                $emoji = $m['emoji'] ?? ($meta['emojis'][$i] ?? null);
                $keywords = array_values(array_filter(array_map('strval', (array) ($m['keywords'] ?? []))));

                $existing = $pack->stickers()->where('checksum', $checksum)->first();

                if ($existing) {
                    $existing->update([
                        'position' => $i,
                        'emoji' => $emoji ?? $existing->emoji,
                        'keywords' => $keywords ?: $existing->keywords,
                    ]);
                    $updated++;
                    continue;
                }

                $path = trim(config('stickers.directory'), '/') . "/{$pack->uuid}/{$checksum}.{$ext}";
                $disk->put($path, file_get_contents($file), 'public');

                $pack->stickers()->create([
                    'file_path' => $path,
                    'format' => $info['format'],
                    'is_animated' => $info['animated'],
                    'width' => $info['width'],
                    'height' => $info['height'],
                    'file_size' => filesize($file),
                    'checksum' => $checksum,
                    'emoji' => $emoji,
                    'keywords' => $keywords ?: null,
                    'position' => $i,
                ]);
                $added++;
            }

            $pack->stickers_count = $pack->stickers()->count();
            $pack->is_animated = $pack->stickers()->where('is_animated', true)->exists();

            if (!$pack->cover_sticker_id || !$pack->stickers()->whereKey($pack->cover_sticker_id)->exists()) {
                $pack->cover_sticker_id = optional($pack->stickers()->first())->id;
            }

            $pack->save();

            return $pack;
        });

        return [
            'name' => $pack->name,
            'slug' => $pack->slug,
            'added' => $added,
            'updated' => $updated,
            'skipped' => $skipped,
            'total' => $pack->stickers_count,
        ];
    }

    protected function inspect(string $file, string $ext): ?array
    {
        if ($ext === 'json') {
            $data = json_decode(file_get_contents($file), true);

            if (!is_array($data) || !isset($data['layers'])) {
                return null;
            }

            return ['format' => 'lottie', 'animated' => true, 'width' => $data['w'] ?? null, 'height' => $data['h'] ?? null];
        }

        $size = @getimagesize($file);
        if (!$size) {
            return null;
        }

        $head = (string) file_get_contents($file, false, null, 0, 1024);

        $animated = match (true) {
            $ext === 'webp' => str_contains($head, 'ANIM'),
            $ext === 'png' => str_contains($head, 'acTL'),
            $ext === 'gif' => preg_match_all('/\x00\x21\xF9\x04/', (string) file_get_contents($file)) > 1,
            default => false,
        };

        return [
            'format' => $ext === 'jpeg' ? 'jpg' : $ext,
            'animated' => $animated,
            'width' => $size[0],
            'height' => $size[1],
        ];
    }
}