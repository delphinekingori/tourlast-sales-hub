<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo Travel Sales data (local only, called from DemoSeeder). Two travel
 * salespeople, then each module's seeder in dependency order. Every password
 * is "password".
 */
class TravelDemoSeeder extends Seeder
{
    /** Module seeders in the order they depend on each other. */
    public const Modules = [
        Travel\ProvidersSeeder::class,
        Travel\MediaSeeder::class,
        Travel\PackagesSeeder::class,
        Travel\InfluencersSeeder::class,
        Travel\BookingsSeeder::class,
        Travel\PaymentsSeeder::class,
        Travel\FlightsSeeder::class,
    ];

    public function run(): void
    {
        foreach ([['Aisha Njeri', 'aisha@tourlast.test'], ['Kevin Otieno', 'kevin@tourlast.test']] as [$name, $email]) {
            $user = User::query()->firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => Hash::make('password'),
                'region' => 'Nairobi',
                'job_title' => 'Travel Sales Executive',
            ]);
            $user->syncRoles([Role::TravelSalesperson->value]);
        }

        foreach (self::Modules as $seeder) {
            if (class_exists($seeder)) {
                $this->call($seeder);
            }
        }
    }
}
