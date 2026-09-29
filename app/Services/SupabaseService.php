<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SupabaseService
{
    /**
     * Upload a voice note to Supabase Storage.
     *
     * Returns ['path' => 'chats/12/uuid.m4a', 'url' => 'https://.../public/...'].
     */
    public function uploadVoice(UploadedFile $file, string $folder = 'voice-notes'): array
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: 'm4a');

        $path = trim($folder, '/') . '/' . Str::uuid() . '.' . $ext;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->serviceKey(),
            'apikey'        => $this->serviceKey(),
            'x-upsert'      => 'false',
        ])
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

        return [
            'path' => $path,
            'url'  => $this->publicUrl($path),
        ];
    }

    /**
     * Delete a file from Supabase Storage using the public URL we stored
     * in messages.attachment_path. Does nothing for URLs that don't
     * belong to our bucket.
     */
    public function deleteByUrl(string $url): void
    {
        $prefix = $this->baseUrl() . '/storage/v1/object/public/' . $this->bucket() . '/';

        if (!str_starts_with($url, $prefix)) {
            return;
        }

        $path = substr($url, strlen($prefix));

        Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->serviceKey(),
            'apikey'        => $this->serviceKey(),
        ])
            ->timeout(30)
            ->delete($this->baseUrl() . '/storage/v1/object/' . $this->bucket() . '/' . $path);
    }

    public function publicUrl(string $path): string
    {
        return $this->baseUrl() . '/storage/v1/object/public/' . $this->bucket() . '/' . $path;
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
}