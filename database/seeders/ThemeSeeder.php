<?php

namespace Database\Seeders;

use App\Models\Theme;
use Illuminate\Database\Seeder;

class ThemeSeeder extends Seeder
{
    public function run(): void
    {
        $presets = [
            ['name' => 'Light', 'background_color' => '#FFFFFF', 'text_color' => '#000000', 'accent_color' => '#FF6600'],
            ['name' => 'Dark',  'background_color' => '#121212', 'text_color' => '#FFFFFF', 'accent_color' => '#FF6600'],
            ['name' => 'Ocean Blue', 'background_color' => '#0D47A1', 'text_color' => null, 'accent_color' => '#82B1FF'],
            ['name' => 'Sunset Orange', 'background_color' => '#FF7043', 'text_color' => null, 'accent_color' => '#FFCCBC'],
        ];

        foreach ($presets as $preset) {
            Theme::updateOrCreate(
                ['name' => $preset['name'], 'type' => 'preset'],
                array_merge($preset, ['type' => 'preset'])
            );
        }
    }
}