<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Integrations\Tourlast\ProviderSource;
use App\Livewire\Admin\Integration;
use App\Models\Lead;
use App\Models\Onboarding;
use App\Models\SandboxProvider;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class SampleDataGuardTest extends TestCase
{
    use RefreshDatabase;

    private function useEnvironment(string $environment): void
    {
        $this->app->detectEnvironment(fn (): string => $environment);
    }

    public function test_a_fresh_seed_with_the_api_source_creates_no_sample_rows(): void
    {
        $this->useEnvironment('local');
        config(['tourlast.source' => 'api']);

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        $this->assertSame(0, User::count());
        $this->assertSame(0, Lead::count());
        $this->assertSame(0, Onboarding::count());
        $this->assertSame(0, SandboxProvider::count());
    }

    public function test_a_fresh_seed_in_production_creates_no_sample_rows_even_with_the_sandbox_source(): void
    {
        $this->useEnvironment('production');
        config(['tourlast.source' => 'sandbox']);

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        $this->assertSame(0, User::count());
        $this->assertSame(0, SandboxProvider::count());
    }

    public function test_the_demo_seeder_is_refused_outside_local_development(): void
    {
        $this->useEnvironment('production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DemoSeeder is for local development only');

        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
    }

    public function test_the_local_sandbox_seed_still_creates_sample_rows(): void
    {
        $this->useEnvironment('local');
        config(['tourlast.source' => 'sandbox']);

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        $this->assertGreaterThan(0, User::count());
        $this->assertGreaterThan(0, Onboarding::count());
    }

    public function test_the_sandbox_source_is_refused_in_production(): void
    {
        $this->useEnvironment('production');
        config(['tourlast.source' => 'sandbox']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TOURLAST_SOURCE=sandbox is for local development only');

        app(ProviderSource::class);
    }

    public function test_the_sandbox_source_is_still_available_locally(): void
    {
        $this->useEnvironment('local');
        config(['tourlast.source' => 'sandbox']);

        $this->assertSame('sandbox', app(ProviderSource::class)->name());
    }

    public function test_the_integration_simulator_is_refused_in_production_and_works_locally(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->withRole(Role::SuperAdmin)->create();
        config(['tourlast.source' => 'sandbox']);

        $this->useEnvironment('production');
        Livewire::actingAs($admin)->test(Integration::class)->call('openSample')->assertForbidden();

        $this->useEnvironment('local');
        Livewire::actingAs($admin)->test(Integration::class)->call('openSample')->assertOk()->assertSet('showSample', true);
    }
}
