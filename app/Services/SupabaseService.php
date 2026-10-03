<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Talks to Supabase Storage. The bucket is PRIVATE: files can only be
 * uploaded/deleted with the service key (server-side), and can only be
 * played through short-lived signed URLs.
 */
class SupabaseService
{
    /**
     * Upload a voice note. Returns ['path' => 'chats/12/uuid.m4a'].
     * Only the path is stored in the database.
     */
    public function uploadVoice(UploadedFile $file, string $folder = 'voice-notes'): array
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: 'm4a');

        $path = trim($folder, '/') . '/' . Str::uuid() . '.' . $ext;

        $response = Http::withHeaders($this->authHeaders() + ['x-upsert' => 'false'])
            ->timeout(60)
            ->withBody(
                file_get_contents($file->getRealPath()),
                $this->contentTypeFor($ext)
            )
            ->post($this->baseUrl() . '/storage/v1/object/' . $this->bucket() . '/' . $path);

        if ($response->failed()) {
            throw new \RuntimeException(
                'Supabase upload failed (' . $response->status() . '): ' . $response->body()
            );
        }

        return ['path' => $path];
    }

    /**
     * Returns a temporary playable URL for a stored path.
     *
     * Signed URLs are cached until shortly before they expire, so opening a
     * chat full of voice notes doesn't hit Supabase once per note every time.
     * Pass $fresh = true to throw the cached link away and sign again (used
     * when the app reports that a link didn't work).
     */
    public function signedUrl(string $path, bool $fresh = false): string
    {
        $ttl = $this->ttl();

        $cacheKey = 'supabase-signed:' . $this->bucket() . ':' . $path;

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        // Cache slightly shorter than the URL lifetime so we never hand out
        // a link that is about to die.
        $cacheSeconds = max(60, $ttl - 600);

        return Cache::remember(
            $cacheKey,
            $cacheSeconds,
            function () use ($path, $ttl) {
                $response = Http::withHeaders($this->authHeaders())
                    ->timeout(20)
                    ->post(
                        $this->baseUrl() . '/storage/v1/object/sign/' . $this->bucket() . '/' . $path,
                        ['expiresIn' => $ttl]
                    );

                if ($response->failed()) {
                    throw new \RuntimeException(
                        'Supabase sign failed (' . $response->status() . '): ' . $response->body()
                    );
                }

                $signed = (string) ($response->json('signedURL') ?? $response->json('signedUrl') ?? '');

                if ($signed === '') {
                    throw new \RuntimeException('Supabase returned no signed URL.');
                }

                // Supabase returns either "/object/sign/..." or
                // "/storage/v1/object/sign/..." depending on version.
                if (str_starts_with($signed, '/storage/v1')) {
                    return $this->baseUrl() . $signed;
                }

                return $this->baseUrl() . '/storage/v1' . $signed;
            }
        );
    }

    /**
     * Safe wrapper used when building API responses: takes whatever is
     * stored (a path, or a legacy full URL) and returns a link the app can
     * play, or null if one can't be made. Never throws, so one bad voice
     * note can't break loading a whole chat.
     */
    public function playableUrl(?string $pathOrUrl, bool $fresh = false): ?string
    {
        if (!$pathOrUrl) {
            return null;
        }

        if (str_starts_with($pathOrUrl, 'http')) {
            $path = $this->toPath($pathOrUrl);

            // Not one of our Supabase public URLs (e.g. already a signed
            // link or another host): leave it as it is.
            if ($path === null) {
                return $pathOrUrl;
            }
        } else {
            $path = $pathOrUrl;
        }

        try {
            return $this->signedUrl($path, $fresh);
        } catch (\Throwable $e) {
            Log::warning('Could not sign Supabase URL', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Delete a stored file by its path (a legacy full public URL also works).
     */
    public function delete(string $pathOrUrl): void
    {
        $path = $this->toPath($pathOrUrl);

        if ($path === null) {
            return;
        }

        Http::withHeaders($this->authHeaders())
            ->timeout(30)
            ->delete($this->baseUrl() . '/storage/v1/object/' . $this->bucket() . '/' . $path);

        Cache::forget('supabase-signed:' . $this->bucket() . ':' . $path);
    }

    /**
     * Kept so older callers that still use the previous method name work.
     */
    public function deleteByUrl(string $pathOrUrl): void
    {
        $this->delete($pathOrUrl);
    }

    // ============================================================
    // HELPERS
    // ============================================================

    private function toPath(string $pathOrUrl): ?string
    {
        if (!str_starts_with($pathOrUrl, 'http')) {
            return $pathOrUrl;
        }

        $prefix = $this->baseUrl() . '/storage/v1/object/public/' . $this->bucket() . '/';

        if (str_starts_with($pathOrUrl, $prefix)) {
            return substr($pathOrUrl, strlen($prefix));
        }

        return null;
    }

    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->serviceKey(),
            'apikey'        => $this->serviceKey(),
        ];
    }

    private function contentTypeFor(string $ext): string
    {
        return match ($ext) {
            'm4a', 'mp4' => 'audio/mp4',
            'aac'        => 'audio/aac',
            'mp3'        => 'audio/mpeg',
            'ogg', 'opus' => 'audio/ogg',
            'wav'        => 'audio/wav',
            'webm'       => 'audio/webm',
            '3gp'        => 'audio/3gpp',
            default      => 'application/octet-stream',
        };
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.supabase.url'), '/');
    }

    private function serviceKey(): string
    {
        return (string) config('services.supabase.service_key');
    }

    private function bucket(): string
    {
        return (string) config('services.supabase.bucket', 'voice-notes');
    }

    private function ttl(): int
    {
        return (int) config('services.supabase.signed_url_ttl', 43200);
    }
}