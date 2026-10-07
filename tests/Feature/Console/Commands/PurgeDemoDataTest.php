<?php

namespace Tests\Feature\Console\Commands;

use App\Actions\IssueReferralCode;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\Onboarding;
use App\Models\ReferralCode;
use App\Models\SandboxProvider;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeDemoDataTest extends TestCase
{
    use RefreshDatabase;

    private User $demoUser;

    private User $realUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->demoUser = User::factory()->withRole(Role::Salesperson)->create(['email' => 'demo.rep@tourlast.test']);
        $this->realUser = User::factory()->withRole(Role::Salesperson)->create(['email' => 'real@tourlast.com']);
        app(IssueReferralCode::class)->handle($this->demoUser);
        app(IssueReferralCode::class)->handle($this->realUser);
    }

    public function test_it_is_a_dry_run_by_default_and_deletes_nothing(): void
    {
        Lead::factory()->for($this->demoUser)->create();
        SandboxProvider::factory()->create(['property_id' => 'TL-12345']);

        $this->artisan('hub:purge-demo-data')
            ->expectsOutputToContain('Dry run: nothing will be deleted')
            ->expectsOutputToContain('Re-run with --force')
            ->assertSuccessful();

        $this->assertTrue($this->demoUser->exists());
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('sandbox_providers', 1);
    }

    public function test_dry_run_wins_over_force(): void
    {
        $this->artisan('hub:purge-demo-data', ['--force' => true, '--dry-run' => true])
            ->expectsOutputToContain('Dry run: nothing will be deleted')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'demo.rep@tourlast.test']);
    }

    public function test_force_deletes_only_demo_users_and_tl_rows_after_warning_about_a_backup(): void
    {
        Lead::factory()->for($this->demoUser)->create();
        Lead::factory()->for($this->realUser)->create();
        SandboxProvider::factory()->create(['property_id' => 'TL-12345']);
        SandboxProvider::factory()->create(['property_id' => 'KE-777']);
        SandboxProvider::factory()->create(['property_id' => 'TL-12345-REAL']);
        Onboarding::factory()->create(['tourlast_property_id' => 'TL-54321']);
        Onboarding::factory()->create(['tourlast_property_id' => 'abc-9']);
        $attributed = Onboarding::factory()->forSalesperson($this->demoUser)->create(['tourlast_property_id' => 'real-1']);

        $this->artisan('hub:purge-demo-data', ['--force' => true])
            ->expectsOutputToContain('Take a database backup')
            ->expectsOutputToContain('1 real onboarding(s) are attributed to a demo salesperson')
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['email' => 'demo.rep@tourlast.test']);
        $this->assertDatabaseHas('users', ['email' => 'real@tourlast.com']);
        $this->assertSame(1, ReferralCode::count());
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseHas('leads', ['user_id' => $this->realUser->id]);
        $this->assertSame(['KE-777', 'TL-12345-REAL'], SandboxProvider::orderBy('property_id')->pluck('property_id')->all());
        $this->assertSame(['abc-9', 'real-1'], Onboarding::withTrashed()->orderBy('tourlast_property_id')->pluck('tourlast_property_id')->all());
        $this->assertNull($attributed->fresh()->user_id);
    }

    public function test_it_does_not_match_lookalike_email_domains(): void
    {
        User::factory()->create(['email' => 'someone@tourlast.test.example.com']);
        User::factory()->create(['email' => 'someone@nottourlast.test.org']);

        $this->artisan('hub:purge-demo-data', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'someone@tourlast.test.example.com']);
        $this->assertDatabaseHas('users', ['email' => 'someone@nottourlast.test.org']);
    }

    public function test_it_removes_everything_a_full_demo_seed_creates_and_keeps_roles_and_real_users(): void
    {
        config(['tourlast.source' => 'sandbox']);
        $this->seed(DemoSeeder::class);
        $this->assertGreaterThan(0, Onboarding::count());

        $this->artisan('hub:purge-demo-data', ['--force' => true])->assertSuccessful();

        $this->assertSame(['real@tourlast.com'], User::pluck('email')->all());
        $this->assertSame(0, Onboarding::withTrashed()->count());
        $this->assertSame(0, SandboxProvider::count());
        $this->assertSame(0, Lead::count());
        $this->assertDatabaseCount('partner_accounts', 0);
        $this->assertDatabaseCount('property_engagements', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseHas('roles', ['name' => Role::SuperAdmin->value]);
    }
}
