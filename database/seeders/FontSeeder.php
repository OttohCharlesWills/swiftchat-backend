<?php

namespace Database\Seeders;

use App\Models\Font;
use Illuminate\Database\Seeder;

class FontSeeder extends Seeder
{
    public function run(): void
    {
        $fonts = [
            ['name' => 'Default', 'font_family' => 'default', 'is_default' => true, 'sort_order' => 1],
            ['name' => 'Roboto', 'font_family' => 'roboto', 'sort_order' => 2],
            ['name' => 'Poppins', 'font_family' => 'poppins', 'sort_order' => 3],
            ['name' => 'Open Sans', 'font_family' => 'open_sans', 'sort_order' => 4],
            ['name' => 'Lato', 'font_family' => 'lato', 'sort_order' => 5],
            ['name' => 'Montserrat', 'font_family' => 'montserrat', 'sort_order' => 6],
        ];

        foreach ($fonts as $font) {
            Font::updateOrCreate(['font_family' => $font['font_family']], $font);
        }
    }
}