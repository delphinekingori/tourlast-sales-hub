<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

#[Signature('hub:create-super-admin {email} {name} {--password= : Leave empty to generate a strong password}')]
#[Description('Create the first Super Admin account (there is no public registration)')]
class CreateSuperAdmin extends Command
{
    public function handle(): int
    {
        $email = Str::lower($this->argument('email'));
        $password = $this->option('password') ?: Str::password(16);

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email', 'unique:users,email'], 'password' => [Password::defaults()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $this->callSilently('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        $user = User::create([
            'name' => $this->argument('name'),
            'email' => $email,
            'password' => $password,
            'is_active' => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole(Role::SuperAdmin->value);

        $this->components->info("Super Admin {$user->email} created.");

        if (! $this->option('password')) {
            $this->components->twoColumnDetail('Generated password', $password);
            $this->components->warn('Store it safely and change it after the first login.');
        }

        return self::SUCCESS;
    }
}
