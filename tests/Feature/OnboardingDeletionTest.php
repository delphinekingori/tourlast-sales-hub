<?php

namespace Tests\Feature;

use App\Actions\IssueReferralCode;
use App\Actions\SyncOnboardings;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Incentives\AccountPoints;
use App\Integrations\Tourlast\ProviderRecordMapper;
use App\Integrations\Tourlast\ProviderSource;
use App\Livewire\Admin\Integration;
use App\Livewire\Onboardings\Mine;
use App\Models\Lead;
use App\Models\Onboarding;
use App\Models\PartnerAccount;
use App\Models\PointEntry;
use App\Models\PropertyEngagement;
use App\Models\SandboxProvider;
use App\Models\SyncRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Stream 3: the source app reports a property deleted (is_deleted / deleted_at).
 * The Hub archives it, keeps its credit, and puts everything back when the
 * property is listed again.
 */
class OnboardingDeletionTest extends TestCase
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

    public function test_a_deleted_feed_row_archives_the_property_its_lead_and_its_registry_record(): void
    {
        $provider = $this->liveProperty(['property_name' => 'Coral Bay Hotel', 'contact_email' => 'gm@coralbay.co.ke']);
        $lead = Lead::factory()->for($this->john)->create(['contact_email' => 'gm@coralbay.co.ke', 'status' => LeadStatus::LinkSent]);
        $engagement = PropertyEngagement::factory()->create(['tourlast_property_id' => $provider->property_id]);

        $this->sync();

        $onboarding = Onboarding::sole();
        $this->assertNotNull($onboarding->credited_at);
        $this->assertNotNull($onboarding->property_engagement_id);
        $this->assertSame(LeadStatus::Onboarded, $lead->fresh()->status);

        $this->feed([$this->tombstone($provider, CarbonImmutable::parse('2026-09-20 08:30'))]);
        $run = $this->sync();

        $this->assertSame(0, Onboarding::count());
        $archived = Onboarding::withTrashed()->sole();
        $this->assertTrue($archived->trashed());
        $this->assertTrue($archived->deleted_at->equalTo(CarbonImmutable::parse('2026-09-20 08:30')));
        $this->assertNotNull($archived->credited_at);
        $this->assertTrue($archived->user->is($this->john));
        $this->assertTrue($lead->fresh()->trashed());
        $this->assertTrue($engagement->fresh()->trashed());

        $this->assertSame(1, $run->records_deleted);
        $this->assertSame(0, $run->records_created + $run->records_updated);
        $this->assertContains('property_deleted', $this->john->notifications()->pluck('data')->pluck('type')->all());
    }

    public function test_a_later_live_row_restores_the_property_its_lead_and_its_registry_record(): void
    {
        $provider = $this->liveProperty(['property_name' => 'Coral Bay Hotel', 'contact_email' => 'gm@coralbay.co.ke']);
        $lead = Lead::factory()->for($this->john)->create(['contact_email' => 'gm@coralbay.co.ke', 'status' => LeadStatus::LinkSent]);
        $engagement = PropertyEngagement::factory()->create(['tourlast_property_id' => $provider->property_id]);
        $this->sync();

        $this->feed([$this->tombstone($provider, CarbonImmutable::parse('2026-09-20 08:30'))]);
        $this->sync();
        $this->assertSame(0, Onboarding::count());

        $this->feed([$this->liveRow($provider, CarbonImmutable::parse('2026-09-21 09:00'))]);
        $run = $this->sync();

        $onboarding = Onboarding::sole();
        $this->assertFalse($onboarding->trashed());
        $this->assertNotNull($onboarding->credited_at);
        $this->assertFalse($lead->fresh()->trashed());
        $this->assertFalse($engagement->fresh()->trashed());

        $this->assertSame(0, $run->records_deleted);
        $this->assertSame(1, $run->records_updated);
        $this->assertContains('property_restored', $this->john->notifications()->pluck('data')->pluck('type')->all());
    }

    public function test_deleting_a_property_never_changes_the_points_it_already_earned(): void
    {
        $this->liveProperty(['account_id' => 'H-1', 'inventory_count' => 40, 'property_name' => 'Coral Bay Hotel']);
        $doomed = $this->liveProperty(['account_id' => 'H-1', 'inventory_count' => 10, 'property_name' => 'Palm Lodge']);
        $this->sync();

        $account = PartnerAccount::sole();
        $earned = (float) PointEntry::counting()->sum('points');
        $inventory = $account->activation_inventory;
        $this->assertGreaterThan(0, $earned);

        $this->feed([$this->tombstone($doomed, CarbonImmutable::parse('2026-09-20 08:30'))]);
        $this->sync();

        $this->assertSame($earned, (float) PointEntry::counting()->sum('points'));
        $this->assertSame($inventory, $account->fresh()->activation_inventory);
        $this->assertNotNull(Onboarding::withTrashed()->where('property_name', 'Palm Lodge')->sole()->credited_at);

        app(AccountPoints::class)->refresh($account->fresh());

        $this->assertSame($earned, (float) PointEntry::counting()->sum('points'));
        $this->assertSame($inventory, $account->fresh()->activation_inventory);
    }

    public function test_management_is_alerted_when_a_property_is_deleted(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $provider = $this->liveProperty(['property_name' => 'Coral Bay Hotel']);
        $this->sync();

        $this->feed([$this->tombstone($provider, CarbonImmutable::parse('2026-09-20 08:30'))]);
        $this->sync();

        $this->assertContains('property_deleted', $manager->notifications()->pluck('data')->pluck('type')->all());
        $this->assertContains('property_deleted', $this->john->notifications()->pluck('data')->pluck('type')->all());
    }

    public function test_admins_see_the_deleted_badge_and_can_restore_the_property(): void
    {
        $provider = $this->liveProperty(['property_name' => 'Coral Bay Hotel']);
        $this->sync();
        $this->feed([$this->tombstone($provider, CarbonImmutable::parse('2026-09-20 08:30'))]);
        $this->sync();

        $admin = User::factory()->withRole(Role::SuperAdmin)->create();

        Livewire::actingAs($admin)->test(Integration::class)
            ->assertSee('Deleted properties')
            ->assertSee('Coral Bay Hotel')
            ->assertSee('1 deleted')
            ->call('restore', Onboarding::withTrashed()->sole()->id);

        $this->assertFalse(Onboarding::sole()->trashed());
        $this->assertSame(0, Onboarding::onlyTrashed()->count());
    }

    public function test_the_salesperson_sees_the_deleted_badge_on_their_own_onboardings(): void
    {
        $provider = $this->liveProperty(['property_name' => 'Coral Bay Hotel']);
        $this->sync();
        $this->feed([$this->tombstone($provider, CarbonImmutable::parse('2026-09-20 08:30'))]);
        $this->sync();

        Livewire::actingAs($this->john)->test(Mine::class)
            ->assertSee('Coral Bay Hotel')
            ->assertSee('Deleted');
    }

    public function test_the_push_endpoint_accepts_tombstones_and_counts_them(): void
    {
        $code = $this->john->referralCode->code;

        $this->sharedToken('shared-secret')->postJson('/api/v1/integrations/tourlast/providers', [
            'provider' => [
                'property_id' => 'TL-9001',
                'ref_code' => $code,
                'property_name' => 'Coral Bay Hotel',
                'property_type' => 'hotel',
                'status' => 'active',
                'active_at' => now()->toIso8601String(),
            ],
        ])->assertOk()->assertJsonPath('data.0.result', 'created');

        $deletedAt = CarbonImmutable::parse('2026-09-20 08:30');
        $this->assertSame(1, Onboarding::count());

        $this->sharedToken('shared-secret')->postJson('/api/v1/integrations/tourlast/providers', [
            'provider' => [
                'property_id' => 'TL-9001',
                'property_name' => 'Coral Bay Hotel',
                'is_deleted' => true,
                'deleted_at' => $deletedAt->toIso8601String(),
                'updated_at' => $deletedAt->toIso8601String(),
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.0.result', 'deleted')
            ->assertJsonPath('meta.deleted', 1);

        $archived = Onboarding::withTrashed()->sole();
        $this->assertTrue($archived->trashed());
        $this->assertTrue($archived->deleted_at->equalTo($deletedAt));

        $this->sharedToken('shared-secret')->postJson('/api/v1/integrations/tourlast/providers', [
            'provider' => ['property_id' => 'TL-9001', 'is_deleted' => false, 'property_name' => 'Coral Bay Hotel'],
        ])->assertOk()->assertJsonPath('data.0.result', 'updated');

        $this->assertFalse(Onboarding::sole()->trashed());
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
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function feed(array $rows): void
    {
        $mapper = app(ProviderRecordMapper::class);

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

    /**
     * @return array<string, mixed>
     */
    private function tombstone(SandboxProvider $provider, CarbonImmutable $deletedAt): array
    {
        return [
            'property_id' => $provider->property_id,
            'ref_code' => $provider->ref_code,
            'property_name' => $provider->property_name,
            'property_type' => $provider->property_type,
            'status' => $provider->status,
            'is_deleted' => true,
            'deleted_at' => $deletedAt->toIso8601String(),
            'updated_at' => $deletedAt->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function liveRow(SandboxProvider $provider, CarbonImmutable $updatedAt): array
    {
        return [
            'property_id' => $provider->property_id,
            'ref_code' => $provider->ref_code,
            'property_name' => $provider->property_name,
            'property_type' => $provider->property_type,
            'status' => $provider->status,
            'active_at' => $provider->active_at?->toIso8601String(),
            'updated_at' => $updatedAt->toIso8601String(),
            'is_deleted' => false,
        ];
    }

    private function sync(string $mode = 'incremental'): SyncRun
    {
        return app(SyncOnboardings::class)->handle($mode);
    }

    private function sharedToken(string $token): static
    {
        config(['tourlast.api.token' => $token]);
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
