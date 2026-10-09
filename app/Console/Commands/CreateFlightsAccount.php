<?php

namespace App\Console\Commands;

use App\Actions\IssueApiToken;
use App\Enums\ApiScope;
use App\Enums\Permission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Signature('travel:create-flights-account {--email=flights-integration@tourlast.com : Email of the integration account} {--name=Flights Super Admin integration : Display name} {--rotate : Revoke the account\'s existing tokens and issue a new one}')]
#[Description('Create the least-privilege account Flights Super Admin uses to push bookings, and print its API token')]
class CreateFlightsAccount extends Command
{
    public function handle(IssueApiToken $issueApiToken): int
    {
        $this->callSilently('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        $email = Str::lower(trim((string) $this->option('email')));
        $user = User::query()->where('email', $email)->first();

        if ($user && $user->getRoleNames()->isNotEmpty()) {
            $this->components->error("{$email} belongs to a person with a Hub role. Use a dedicated integration email.");

            return self::FAILURE;
        }

        if ($user && $user->tokens()->exists() && ! $this->option('rotate')) {
            $this->components->error("{$email} already has a token. Run again with --rotate to replace it.");

            return self::FAILURE;
        }

        $user ??= User::query()->create([
            'name' => (string) $this->option('name'),
            'email' => $email,
            // Nobody signs in as this account: a long random password nobody knows.
            'password' => Hash::make(Str::random(64)),
            'is_active' => true,
            'job_title' => 'System integration',
        ]);

        $user->syncPermissions([Permission::PushFlightBookings->value]);
        $user->tokens()->delete();

        $token = $issueApiToken->handle($user, 'Flights Super Admin push', [ApiScope::FlightsPush->value]);

        $this->components->info("Integration account {$user->email} is ready. It can only push flight bookings.");
        $this->line('Give this token to the Flights developer (shown once, store it as a secret):');
        $this->newLine();
        $this->line('  '.$token->plainTextToken);
        $this->newLine();
        $this->line('  POST '.url('/api/v1/integrations/flights/bookings').'   Authorization: Bearer <token>');
        $this->line('  Then set FLIGHTS_SOURCE=push in the Hub .env.');

        return self::SUCCESS;
    }
}
