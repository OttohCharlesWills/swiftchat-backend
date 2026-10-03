<?php

namespace App\Console\Commands;

use App\Services\Stickers\StickerImporter;
use Illuminate\Console\Command;

class ImportStickers extends Command
{
    protected $signature = 'stickers:import
                            {path : Folder, folder of folders, or .zip/.wastickers file}
                            {--official : Mark imported packs as official}';

    protected $description = 'Import sticker packs from a folder or archive';

    public function handle(StickerImporter $importer): int
    {
        try {
            $result = $importer->import($this->argument('path'), [
                'source' => 'cli',
                'official' => $this->option('official') ? true : null,
            ]);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if (!$result['packs']) {
            $this->warn('No sticker packs found at that path.');
        } else {
            $this->table(
                ['Pack', 'Slug', 'Added', 'Updated', 'Skipped', 'Total'],
                array_map(fn ($p) => array_values($p), $result['packs'])
            );
        }

        foreach ($result['warnings'] as $w) {
            $this->warn($w);
        }

        return self::SUCCESS;
    }
}