<?php

declare(strict_types=1);

namespace Database\Seeders;

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
        // The demo is the landing page's whole call to action, so it is part of
        // the default seed: `migrate:fresh --seed` and the container's boot
        // sequence both produce a working demo.
        $this->call(DemoProjectSeeder::class);
    }
}
