<?php

namespace Tests\Feature;

use App\Actions\IssueReferralCode;
use App\Enums\Role;
use App\Livewire\Dashboard;
use App\Livewire\Team\Performance;
use App\Models\Activity;
use App\Models\Onboarding;
use App\Models\PointEntry;
use App\Models\ReferralClick;
use App\Models\Target;
use App\Models\User;
use App\Support\Period;
use App\Support\SalesMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProgressAndTargetsTest extends TestCase
{
    use RefreshDatabase;

    private User $john;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-05 10:00'));
        $this->john = User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);
        app(IssueReferralCode::class)->handle($this->john);
        $this->john->refresh();
    }

    public function test_a_salesperson_sets_their_own_target_before_the_lock_day(): void
    {
        Livewire::actingAs($this->john)->test(Dashboard::class)
            ->call('openTarget')
            ->assertSet('targetMonth', '2026-09-01')
            ->set('targetValue', 12)
            ->call('saveTarget')
            ->assertHasNoErrors();

        $this->assertSame(12, Target::sole()->target);
        $this->assertSame('2026-09-01', Target::sole()->month->toDateString());

        Livewire::actingAs($this->john)->test(Dashboard::class)
            ->call('openTarget')
            ->assertSet('targetValue', 12)
            ->set('targetValue', 9)
            ->call('saveTarget')
            ->assertHasNoErrors();

        $this->assertSame(9, Target::sole()->target);
    }

    public function test_this_months_target_locks_after_the_seventh_but_next_month_stays_open(): void
    {
        Target::factory()->for($this->john)->create(['month' => '2026-09-01', 'target' => 8]);
        $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00'));

        Livewire::actingAs($this->john)->test(Dashboard::class)
            ->call('openTarget')
            ->assertSet('targetMonth', '2026-10-01')
            ->set('targetMonth', '2026-09-01')
            ->set('targetValue', 3)
            ->call('saveTarget')
            ->assertHasErrors('targetMonth');

        $this->assertSame(8, Target::where('month', '2026-09-01')->value('target'));
    }

    public function test_managers_can_view_but_not_set_a_salespersons_target(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();

        $this->actingAs($manager)->get(route('team.member', $this->john))->assertOk()->assertSee('John Doe');

        Livewire::actingAs($manager)->test(Dashboard::class, ['user' => $this->john])->call('openTarget')->assertForbidden();
    }

    public function test_salespeople_cannot_open_each_others_progress(): void
    {
        $mary = User::factory()->withRole(Role::Salesperson)->create();

        $this->actingAs($mary)->get(route('team.member', $this->john))->assertForbidden();
    }

    public function test_metrics_count_partners_in_the_month_they_went_live_and_points_by_earned_date(): void
    {
        Target::factory()->for($this->john)->create(['month' => '2026-09-01', 'target' => 10]);
        Onboarding::factory()->forSalesperson($this->john)->active(CarbonImmutable::parse('2026-09-02'))->create(['property_type' => 'hotel']);
        Onboarding::factory()->forSalesperson($this->john)->approved(CarbonImmutable::parse('2026-09-04'))->create(['property_type' => 'villa']);
        Onboarding::factory()->forSalesperson($this->john)->active(CarbonImmutable::parse('2026-08-30'))->create();
        Onboarding::factory()->forSalesperson($this->john)->create(['submitted_at' => CarbonImmutable::parse('2026-08-10')]);
        Onboarding::factory()->forSalesperson($this->john)->rejected()->create();
        ReferralClick::factory()->count(8)->create(['referral_code_id' => $this->john->referralCode->id, 'clicked_at' => CarbonImmutable::parse('2026-09-03')]);

        PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-09-02'))->create(['points' => 5]);
        PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-09-03'))->provisional()->create(['points' => 0.5]);
        PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-09-04'))->create(['points' => 3, 'status' => 'cancelled']);

        $metrics = app(SalesMetrics::class)->forUser($this->john, Period::named('month'));

        $this->assertSame(10, $metrics['target']);
        $this->assertSame(5.5, $metrics['points']);
        $this->assertSame(5.0, $metrics['approvedPoints']);
        $this->assertSame(1, $metrics['onboarded']);
        $this->assertSame(1, $metrics['approvedNotLive']);
        $this->assertSame(2, $metrics['awaiting']);
        $this->assertSame(1, $metrics['stalled']);
        $this->assertSame(8, $metrics['clicks']);
        $this->assertSame(12.5, $metrics['conversion']);
        $this->assertSame(1, $metrics['previousOnboarded']);
        $this->assertSame(['hotel' => 1], $metrics['byType']);
    }

    public function test_the_progress_page_shows_the_target_ring_and_numbers(): void
    {
        Target::factory()->for($this->john)->create(['month' => '2026-09-01', 'target' => 4]);
        PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-09-02'))->create(['points' => 1]);

        $this->actingAs($this->john)->get('/')
            ->assertOk()
            ->assertSee('25%')
            ->assertSee('3 points to go');
    }

    public function test_team_performance_flags_inactive_and_behind_salespeople(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambui']);
        Target::factory()->for($mary)->create(['month' => '2026-09-01', 'target' => 4]);
        PointEntry::factory()->for($mary)->on(CarbonImmutable::parse('2026-09-02'))->create(['points' => 5]);
        Activity::factory()->for($mary)->create(['happened_at' => now()->subDay()]);

        Livewire::actingAs($manager)->test(Performance::class)
            ->assertSee('Mary Wambui')
            ->assertSee('Target hit')
            ->assertSee('John Doe')
            ->assertSee('No activity yet');
    }

    public function test_salespeople_cannot_open_team_performance(): void
    {
        $this->actingAs($this->john)->get(route('team.performance'))->assertForbidden();
        $this->actingAs($this->john)->get(route('team.targets'))->assertForbidden();
    }
}
