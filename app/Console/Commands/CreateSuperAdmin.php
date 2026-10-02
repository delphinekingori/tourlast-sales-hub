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

#[Signature('hub:create-super-admin')]
#[Description('Interactively create the first Super Admin account (refuses if one already exists)')]
class CreateSuperAdmin extends Command
{
    public function handle(): int
    {
        $this->callSilently('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        if (User::query()->role(Role::SuperAdmin->value)->exists()) {
            $this->components->error('A Super Admin already exists. Invite further people from Admin → Users & Invites.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Full name'));
        $email = Str::lower(trim((string) $this->ask('Email address')));
        $password = (string) $this->secret('Password (at least 10 characters, with letters and numbers)');

        if ($password !== (string) $this->secret('Confirm password')) {
            $this->components->error('The passwords do not match.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'unique:users,email'], 'password' => [Password::defaults()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create(['name' => $name, 'email' => $email, 'password' => $password, 'is_active' => true]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole(Role::SuperAdmin->value);

        $this->components->info("Super Admin {$user->email} created.");

        return self::SUCCESS;
    }
}
