<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prompts_for_the_details_and_creates_the_first_super_admin(): void
    {
        $this->artisan('hub:create-super-admin')
            ->expectsQuestion('Full name', 'Ada Admin')
            ->expectsQuestion('Email address', 'Ada@Tourlast.com')
            ->expectsQuestion('Password (at least 10 characters, with letters and numbers)', 'secret-pass-123')
            ->expectsQuestion('Confirm password', 'secret-pass-123')
            ->expectsOutputToContain('Super Admin ada@tourlast.com created.')
            ->assertSuccessful();

        $user = User::where('email', 'ada@tourlast.com')->firstOrFail();

        $this->assertTrue($user->hasRole(Role::SuperAdmin->value));
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('secret-pass-123', $user->password));
    }

    public function test_it_refuses_when_a_super_admin_already_exists(): void
    {
        $this->artisan('hub:create-super-admin')
            ->expectsQuestion('Full name', 'First Admin')
            ->expectsQuestion('Email address', 'first@tourlast.com')
            ->expectsQuestion('Password (at least 10 characters, with letters and numbers)', 'secret-pass-123')
            ->expectsQuestion('Confirm password', 'secret-pass-123')
            ->assertSuccessful();

        $this->artisan('hub:create-super-admin')
            ->expectsOutputToContain('A Super Admin already exists.')
            ->assertFailed();

        $this->assertSame(1, User::query()->role(Role::SuperAdmin->value)->count());
    }

    public function test_it_rejects_mismatched_passwords(): void
    {
        $this->artisan('hub:create-super-admin')
            ->expectsQuestion('Full name', 'Ada Admin')
            ->expectsQuestion('Email address', 'ada@tourlast.com')
            ->expectsQuestion('Password (at least 10 characters, with letters and numbers)', 'secret-pass-123')
            ->expectsQuestion('Confirm password', 'different-pass-123')
            ->expectsOutputToContain('The passwords do not match.')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_rejects_a_weak_password_and_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@tourlast.com']);

        $this->artisan('hub:create-super-admin')
            ->expectsQuestion('Full name', 'Ada Admin')
            ->expectsQuestion('Email address', 'taken@tourlast.com')
            ->expectsQuestion('Password (at least 10 characters, with letters and numbers)', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertFailed();

        $this->assertSame(1, User::count());
    }
}
