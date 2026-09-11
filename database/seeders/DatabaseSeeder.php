<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            FontSeeder::class,
        ]);

        $this->call([
            FontSeeder::class,
            ThemeSeeder::class,
        ]);

        User::factory()->create([
            'name'         => 'Test User',
            'phone_number' => '+2348000000000',
        ]);
    }
}