<?php

namespace Tests\Feature;

use App\Actions\IssueReferralCode;
use App\Actions\SyncOnboardings;
use App\Enums\EngagementStage;
use App\Enums\EngagementStatus;
use App\Enums\OnboardingStatus;
use App\Enums\Role;
use App\Http\Resources\V1\OnboardingResource;
use App\Integrations\Tourlast\ProviderRecordMapper;
use App\Integrations\Tourlast\ProviderSource;
use App\Livewire\Admin\Integration;
use App\Livewire\Onboardings\Mine;
use App\Models\Onboarding;
use App\Models\PointEntry;
use App\Models\PropertyEngagement;
use App\Models\SandboxProvider;
use App\Models\SyncRun;
use App\Models\User;
use App\Support\PartnerRegisterFilters;
use App\Support\Period;
use App\Support\SalesMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Stream 4: the source app reports a property that went live and later stopped
 * (status "inactive" with an inactive_at date). The Hub keeps the credit the
 * property earned, dates the change from inactive_at, alerts once, and leaves
 * the curated registry record alone.
 */
class OnboardingInactiveTest extends TestCase
{
    use RefreshDatabase;

    private User $john;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tourlast.source' => 'sandbox']);
        $this->travelTo(CarbonImmutable::parse('2026-09-15 12:00'));
        $this->john = User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);
        app(IssueReferralCode::class)->handle($this->john);
        $this->john->refresh();
    }

    public function test_an_inactive_row_stops_the_property_and_dates_the_change_from_inactive_at(): void
    {
        $provider = $this->liveProperty(['property_name' => 'Coral Bay Hotel']);
        $this->sync();
        $this->assertSame(OnboardingStatus::Active, Onboarding::sole()->status);

        $inactiveAt = CarbonImmutable::parse('2026-09-25 10:15');
        $this->feed([$this->inactiveRow($provider, $inactiveAt)]);
        $this->sync();

        $onboarding = Onboarding::sole();
        $this->assertSame(OnboardingStatus::Inactive, $onboarding->status);
        $this->assertTrue($onboarding->inactive_at->equalTo($inactiveAt));
        $this->assertTrue($onboarding->user->is($this->john));

        $change = $onboarding->statusChanges()->where('to_status', OnboardingStatus::Inactive)->sole();
        $this->assertSame(OnboardingStatus::Active, $change->from_status);
        $this->assertTrue($change->occurred_at->equalTo($inactiveAt));
    }

    public function test_an_inactive_row_without_inactive_at_falls_back_to_updated_at(): void
    {
        $provider = $this->liveProperty();
        $this->sync();

        $updatedAt = CarbonImmutable::parse('2026-09-20 08:30');
        $this->feed([
            'property_id' => $provider->property_id,
            'ref_code' => $provider->ref_code,
            'property_name' => $provider->property_name,
            'property_type' => $provider->property_type,
            'status' => 'inactive',
            'active_at' => $provider->active_at?->toIso8601String(),
            'updated_at' => $updatedAt->toIso8601String(),
        ]);
        $this->sync();

        $onboarding = Onboarding::sole();
        $this->assertSame(OnboardingStatus::Inactive, $onboarding->status);
        $this->assertTrue($onboarding->inactive_at->equalTo($updatedAt));

        $change = $onboarding->statusChanges()->where('to_status', OnboardingStatus::Inactive)->sole();
        $this->assertTrue($change->occurred_at->equalTo($updatedAt));
    }

    public function test_going_inactive_keeps_the_credit_and_points_but_stops_counting_the_property_as_live(): void
    {
        $provider = $this->liveProperty(['account_id' => 'H-1', 'inventory_count' => 40, 'property_name' => 'Coral Bay Hotel']);
        $this->sync();

        $pointsBefore = (float) PointEntry::counting()->sum('points');
        $metricsBefore = app(SalesMetrics::class)->forUser($this->john, Period::named('month'));
        $this->assertGreaterThan(0, $pointsBefore);
        $this->assertSame(1, $metricsBefore['onboarded']);

        $this->feed([$this->inactiveRow($provider, CarbonImmutable::parse('2026-09-25 10:15'))]);
        $this->sync();

        $onboarding = Onboarding::sole();
        $this->assertNotNull($onboarding->credited_at);
        $this->assertSame($pointsBefore, (float) PointEntry::counting()->sum('points'));

        $metrics = app(SalesMetrics::class)->forUser($this->john, Period::named('month'));
        $this->assertSame($pointsBefore, $metrics['points']);
        $this->assertSame(0, $metrics['onboarded']);
        $this->assertSame(0, $metrics['active']);
        $this->assertSame(0, $metrics['awaiting']);
        $this->assertSame(0, Onboarding::query()->onboarded()->count());
        $this->assertSame(0, Onboarding::query()->awaitingApproval()->count());
    }

    public function test_a_property_that_goes_inactive_is_alerted_once_and_not_again(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $provider = $this->liveProperty(['property_name' => 'Coral Bay Hotel']);
        $this->sync();

        $this->feed([$this->inactiveRow($provider, CarbonImmutable::parse('2026-09-25 10:15'))]);
        $this->sync();

        $this->assertSame(1, $this->alertCount($manager));
        $this->assertSame(1, $this->alertCount($this->john));

        $this->sync('full');

        $this->assertSame(1, $this->alertCount($manager));
        $this->assertSame(1, $this->alertCount($this->john));
    }

    public function test_an_inactive_onboarding_does_not_force_the_registry_record_to_active(): void
    {
        $provider = $this->liveProperty();
        $engagement = PropertyEngagement::factory()->stage(EngagementStage::Live, EngagementStatus::Won)->create([
            'tourlast_property_id' => $provider->property_id,
        ]);
        $this->sync();
        $this->assertNotNull(Onboarding::sole()->property_engagement_id);

        $this->feed([$this->inactiveRow($provider, CarbonImmutable::parse('2026-09-25 10:15'))]);
        $this->sync();

        $engagement->refresh();
        $this->assertSame(EngagementStage::Live, $engagement->stage);
        $this->assertSame(EngagementStatus::Won, $engagement->status);
        $this->assertSame(OnboardingStatus::Inactive, Onboarding::sole()->status);
    }

    public function test_inactive_reads_as_a_finished_live_property_in_the_api_and_on_the_salesperson_screen(): void
    {
        $provider = $this->liveProperty(['property_name' => 'Coral Bay Hotel']);
        $this->sync();
        $inactiveAt = CarbonImmutable::parse('2026-09-25 10:15');
        $this->feed([$this->inactiveRow($provider, $inactiveAt)]);
        $this->sync();

        $payload = (new OnboardingResource(Onboarding::sole()->load(['user', 'statusChanges'])))->toArray(Request::create('/'));

        $this->assertSame('inactive', $payload['status']);
        $this->assertSame('Inactive', $payload['status_label']);
        $this->assertSame($inactiveAt->toIso8601String(), $payload['inactive_at']);
        $this->assertSame(5, $payload['progress']['completed']);
        $this->assertFalse($payload['progress']['rejected']);

        $change = collect($payload['status_history'])->firstWhere('to', 'inactive');
        $this->assertSame('Inactive', $change['to_label']);
        $this->assertSame($inactiveAt->toIso8601String(), $change['occurred_at']);

        Livewire::actingAs($this->john)->test(Mine::class)
            ->assertSee('Coral Bay Hotel')
            ->assertSee('Inactive')
            ->set('filter', 'inactive')
            ->assertSee('Coral Bay Hotel');
    }

    public function test_a_property_that_goes_live_again_drops_its_inactive_date(): void
    {
        $provider = $this->liveProperty();
        $this->sync();
        $this->feed([$this->inactiveRow($provider, CarbonImmutable::parse('2026-09-25 10:15'))]);
        $this->sync();
        $this->assertSame(OnboardingStatus::Inactive, Onboarding::sole()->status);

        $this->feed([
            'property_id' => $provider->property_id,
            'ref_code' => $provider->ref_code,
            'property_name' => $provider->property_name,
            'property_type' => $provider->property_type,
            'status' => 'active',
            'active_at' => CarbonImmutable::parse('2026-09-30 09:00')->toIso8601String(),
            'updated_at' => CarbonImmutable::parse('2026-09-30 09:05')->toIso8601String(),
        ]);
        $this->sync();

        $onboarding = Onboarding::sole();
        $this->assertSame(OnboardingStatus::Active, $onboarding->status);
        $this->assertNull($onboarding->inactive_at);
        $this->assertNotNull($onboarding->credited_at);
        $this->assertSame(0, PartnerRegisterFilters::fromArray(['status' => 'inactive'])->query()->count());
    }

    public function test_admins_can_move_a_sandbox_property_to_inactive(): void
    {
        $provider = $this->liveProperty(['property_name' => 'Coral Bay Hotel']);
        $this->sync();
        $admin = User::factory()->withRole(Role::SuperAdmin)->create();

        Livewire::actingAs($admin)->test(Integration::class)
            ->assertSee('Coral Bay Hotel')
            ->assertSee('Inactive')
            ->call('advance', $provider->id, OnboardingStatus::Inactive->value);

        $this->assertSame('inactive', $provider->fresh()->status);
        $this->assertNotNull($provider->fresh()->inactive_at);
        $this->assertSame(OnboardingStatus::Inactive, Onboarding::sole()->status);
        $this->assertNotNull(Onboarding::sole()->inactive_at);
    }

    public function test_the_partner_register_filters_inactive_properties_by_their_inactive_date(): void
    {
        $provider = $this->liveProperty(['property_name' => 'Coral Bay Hotel']);
        $this->sync();
        $inactiveAt = CarbonImmutable::parse('2026-09-25 10:15');
        $this->feed([$this->inactiveRow($provider, $inactiveAt)]);
        $this->sync();

        $filters = PartnerRegisterFilters::fromArray(['status' => 'inactive']);
        $this->assertSame('inactive', $filters->status);
        $this->assertSame('inactive_at', $filters->dateColumn());
        $this->assertSame('Date inactive', $filters->dateLabel());
        $this->assertArrayHasKey('inactive', PartnerRegisterFilters::statusOptions());

        $row = $filters->query()->sole();
        $this->assertTrue($row->is(Onboarding::sole()));
        $this->assertTrue($row->inactive_at->equalTo($inactiveAt));

        $this->assertSame('onboarded', PartnerRegisterFilters::fromArray(['status' => 'not-a-filter'])->status);
    }

    private function alertCount(User $user): int
    {
        return $user->notifications()->pluck('data')->pluck('type')->filter(fn (string $type) => $type === 'property_inactive')->count();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function liveProperty(array $attributes = []): SandboxProvider
    {
        return SandboxProvider::factory()->create([
            'ref_code' => $this->john->referralCode->code,
            'status' => 'active',
            'approved_at' => now()->subDay(),
            'active_at' => now()->subHours(6),
            ...$attributes,
        ]);
    }

    /**
     * The row the source app sends when a live property stops being listed as
     * live: status inactive plus the date it stopped.
     *
     * @return array<string, mixed>
     */
    private function inactiveRow(SandboxProvider $provider, CarbonImmutable $inactiveAt): array
    {
        return [
            'property_id' => $provider->property_id,
            'ref_code' => $provider->ref_code,
            'property_name' => $provider->property_name,
            'property_type' => $provider->property_type,
            'status' => 'inactive',
            'active_at' => $provider->active_at?->toIso8601String(),
            'inactive_at' => $inactiveAt->toIso8601String(),
            'updated_at' => $inactiveAt->addMinutes(5)->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>|array<string, mixed>  $rows
     */
    private function feed(array $rows): void
    {
        $mapper = app(ProviderRecordMapper::class);
        $rows = array_is_list($rows) ? $rows : [$rows];

        $this->app->bind(ProviderSource::class, fn () => new class($mapper, $rows) implements ProviderSource
        {
            /**
             * @param  array<int, array<string, mixed>>  $rows
             */
            public function __construct(private ProviderRecordMapper $mapper, private array $rows) {}

            public function name(): string
            {
                return 'api';
            }

            public function changedSince(?CarbonImmutable $since): iterable
            {
                foreach ($this->rows as $row) {
                    yield $this->mapper->fromArray($row);
                }
            }
        });
    }

    private function sync(string $mode = 'incremental'): SyncRun
    {
        return app(SyncOnboardings::class)->handle($mode);
    }
}
