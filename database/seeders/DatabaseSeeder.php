<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([RolesAndPermissionsSeeder::class, IncentivePolicySeeder::class]);

        if (app()->isLocal() && config('tourlast.source') === 'sandbox') {
            $this->call([DemoSeeder::class, TravelDemoSeeder::class]);
        }
    }
}
