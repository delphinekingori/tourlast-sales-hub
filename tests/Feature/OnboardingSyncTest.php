<?php

namespace Tests\Feature;

use App\Actions\AssignOnboarding;
use App\Actions\IssueReferralCode;
use App\Actions\SyncOnboardings;
use App\Enums\LeadStatus;
use App\Enums\OnboardingStatus;
use App\Enums\Role;
use App\Integrations\Tourlast\ProviderSource;
use App\Models\Lead;
use App\Models\Onboarding;
use App\Models\SandboxProvider;
use App\Models\SyncRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class OnboardingSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $john;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tourlast.source' => 'sandbox']);
        $this->john = User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);
        app(IssueReferralCode::class)->handle($this->john);
        $this->john->refresh();
    }

    public function test_a_signup_through_a_referral_code_is_credited_to_its_owner_once_live(): void
    {
        $provider = SandboxProvider::factory()->create(['ref_code' => strtolower($this->john->referralCode->code), 'property_name' => 'ABC Hotel']);

        $this->sync();

        $onboarding = Onboarding::sole();
        $this->assertTrue($onboarding->user->is($this->john));
        $this->assertSame(OnboardingStatus::Submitted, $onboarding->status);
        $this->assertNull($onboarding->credited_at);

        $provider->update(['status' => 'approved', 'approved_at' => CarbonImmutable::parse('2026-09-08 11:00')]);
        $this->sync();
        $this->assertNull($onboarding->fresh()->credited_at);

        $liveAt = CarbonImmutable::parse('2026-09-10 11:00');
        $provider->update(['status' => 'active', 'active_at' => $liveAt]);
        $this->sync();

        $onboarding->refresh();
        $this->assertSame(OnboardingStatus::Active, $onboarding->status);
        $this->assertTrue($onboarding->credited_at->equalTo($liveAt));
        $this->assertSame(1, Onboarding::onboarded()->where('user_id', $this->john->id)->count());
    }

    public function test_the_activation_date_decides_the_month_not_the_approval_date(): void
    {
        $provider = SandboxProvider::factory()->create(['ref_code' => $this->john->referralCode->code, 'status' => 'approved', 'approved_at' => CarbonImmutable::parse('2026-08-28 10:00')]);
        $this->sync();

        $liveAt = CarbonImmutable::parse('2026-09-02 09:00');
        $provider->update(['status' => 'active', 'active_at' => $liveAt]);
        $this->sync();

        $onboarding = Onboarding::sole();
        $this->assertSame(OnboardingStatus::Active, $onboarding->status);
        $this->assertTrue($onboarding->credited_at->equalTo($liveAt));
        $this->assertCount(2, $onboarding->statusChanges);
    }

    public function test_a_rejected_partner_loses_its_credit(): void
    {
        $provider = SandboxProvider::factory()->create(['ref_code' => $this->john->referralCode->code, 'status' => 'active', 'active_at' => now()]);
        $this->sync();
        $this->assertNotNull(Onboarding::sole()->credited_at);

        $provider->update(['status' => 'rejected', 'rejected_at' => now()]);
        $this->sync();

        $this->assertNull(Onboarding::sole()->credited_at);
        $this->assertSame(0, Onboarding::onboarded()->count());
    }

    public function test_signups_without_a_known_code_are_unattributed(): void
    {
        SandboxProvider::factory()->create(['ref_code' => null]);
        SandboxProvider::factory()->create(['ref_code' => 'TL-NOBODY-0000']);

        $this->sync();

        $this->assertSame(2, Onboarding::unattributed()->count());
    }

    public function test_a_manual_assignment_survives_later_syncs(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $provider = SandboxProvider::factory()->create(['ref_code' => null]);
        $this->sync();

        app(AssignOnboarding::class)->handle(Onboarding::sole(), $this->john, $admin, 'Provider confirmed John guided them.');

        $provider->update(['status' => 'active', 'active_at' => now()]);
        $this->sync();

        $onboarding = Onboarding::sole();
        $this->assertTrue($onboarding->user->is($this->john));
        $this->assertSame('manual', $onboarding->attribution);
        $this->assertNotNull($onboarding->credited_at);
        $this->assertSame('Provider confirmed John guided them.', $onboarding->attributionChanges->first()->reason);
    }

    public function test_a_live_signup_marks_the_matching_lead_onboarded(): void
    {
        $lead = Lead::factory()->for($this->john)->create(['contact_email' => 'gm@abchotel.co.ke', 'status' => LeadStatus::LinkSent]);
        $otherPersonsLead = Lead::factory()->create(['contact_email' => 'gm@abchotel.co.ke']);
        $provider = SandboxProvider::factory()->create(['ref_code' => $this->john->referralCode->code, 'contact_email' => 'GM@abchotel.co.ke']);

        $this->sync();
        $this->assertSame(LeadStatus::LinkSent, $lead->fresh()->status);
        $this->assertNotNull($lead->fresh()->onboarding_id);

        $provider->update(['status' => 'approved', 'approved_at' => now()]);
        $this->sync();
        $this->assertSame(LeadStatus::LinkSent, $lead->fresh()->status);

        $provider->update(['status' => 'active', 'active_at' => now()]);
        $this->sync();

        $this->assertSame(LeadStatus::Onboarded, $lead->fresh()->status);
        $this->assertNull($otherPersonsLead->fresh()->onboarding_id);
    }

    public function test_incremental_sync_reads_only_recent_changes(): void
    {
        $this->travelTo(now()->subHours(2));
        SandboxProvider::factory()->create(['ref_code' => $this->john->referralCode->code]);
        $this->travelTo(now()->addHour());
        $this->sync();
        $this->travelBack();

        SandboxProvider::factory()->create(['ref_code' => $this->john->referralCode->code]);
        $run = $this->sync();

        $this->assertSame(1, $run->records_seen);
        $this->assertSame(2, Onboarding::count());
        $this->assertSame(2, $this->sync('full')->records_seen);
    }

    public function test_a_failing_source_is_logged_and_does_not_throw(): void
    {
        $this->app->bind(ProviderSource::class, fn () => new class implements ProviderSource
        {
            public function name(): string
            {
                return 'database';
            }

            public function changedSince(?CarbonImmutable $since): iterable
            {
                throw new RuntimeException('Access denied for user sales_hub_readonly');
            }
        });

        $run = app(SyncOnboardings::class)->handle();

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('Access denied', $run->error);
        $this->assertSame(1, SyncRun::count());
    }

    public function test_the_sync_command_reports_what_it_did(): void
    {
        SandboxProvider::factory()->count(2)->create(['ref_code' => $this->john->referralCode->code]);

        $this->artisan('hub:sync-tourlast')->expectsOutputToContain('2 new')->assertSuccessful();
    }

    private function sync(string $mode = 'incremental'): SyncRun
    {
        return app(SyncOnboardings::class)->handle($mode);
    }
}
