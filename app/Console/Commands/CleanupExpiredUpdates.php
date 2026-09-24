<?php

namespace App\Console\Commands;

use App\Models\Update;
use App\Services\CloudinaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupExpiredUpdates extends Command
{
    protected $signature = 'updates:cleanup';

    protected $description = 'Delete expired Updates and clean up unused Cloudinary media';

    public function __construct(
        protected CloudinaryService $cloudinary
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $expiredUpdates = Update::whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        if ($expiredUpdates->isEmpty()) {
            $this->info('No expired Updates found.');

            return self::SUCCESS;
        }

        $this->info(
            "Found {$expiredUpdates->count()} expired Update(s)."
        );

        foreach ($expiredUpdates as $update) {
            try {
                $this->cleanupUpdate($update);

                $this->line(
                    "Cleaned Update {$update->uuid}"
                );
            } catch (\Throwable $e) {
                report($e);

                $this->error(
                    "Failed to clean Update {$update->uuid}: {$e->getMessage()}"
                );
            }
        }

        return self::SUCCESS;
    }

    private function cleanupUpdate(Update $update): void
    {
        DB::transaction(function () use ($update) {

            /*
             * Check whether another ACTIVE Update is still using
             * this Cloudinary asset.
             *
             * This matters because reposts reuse the same media.
             */
            $mediaStillUsed = false;

            if ($update->cloudinary_public_id) {
                $mediaStillUsed = Update::where(
                    'cloudinary_public_id',
                    $update->cloudinary_public_id
                )
                    ->where('id', '!=', $update->id)
                    ->where('expires_at', '>', now())
                    ->exists();
            }

            /*
             * Delete Cloudinary media only when no other active
             * Update is using it.
             */
            if (
                $update->cloudinary_public_id &&
                !$mediaStillUsed
            ) {
                $this->cloudinary->delete(
                    $update->cloudinary_public_id,
                    $update->cloudinary_resource_type ?? 'image'
                );
            }

            /*
             * Delete view records.
             */
            $update->views()->delete();

            /*
             * Delete repost tracking records.
             */
            $update->reposts()->delete();

            /*
             * If this was a repost, remove the relationship
             * from the original Update.
             */
            DB::table('update_reposts')
                ->where('update_id', $update->original_update_id)
                ->where('user_id', $update->user_id)
                ->delete();

            /*
             * Finally soft-delete the expired Update.
             */
            $update->delete();
        });
    }
}
