<?php

namespace Tests\Feature;

use App\Actions\ChangeAccountStatus;
use App\Actions\IssueReferralCode;
use App\Actions\SyncOnboardings;
use App\Enums\Role;
use App\Incentives\Earnings;
use App\Incentives\MonthlyEarnings;
use App\Incentives\Statements;
use App\Models\AuditEvent;
use App\Models\IncentiveAgreement;
use App\Models\Onboarding;
use App\Models\PartnerAccount;
use App\Models\PointEntry;
use App\Models\SandboxProvider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgreementWindowAndOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private User $john;

    private User $mary;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tourlast.source' => 'sandbox']);
        $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00'));
        $this->john = User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);
        $this->mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambui']);
        app(IssueReferralCode::class)->handle($this->john);
        app(IssueReferralCode::class)->handle($this->mary);
        $this->john->refresh();
        $this->mary->refresh();
    }

    private function earnings(?CarbonImmutable $month = null): Earnings
    {
        return app(MonthlyEarnings::class)->for($this->john, $month ?? CarbonImmutable::parse('2026-09-01'));
    }

    public function test_points_earned_before_the_agreement_started_are_not_paid(): void
    {
        IncentiveAgreement::factory()->for($this->john)->create(['starts_on' => '2026-09-10']);
        PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-09-03'))->create(['points' => 15]);
        PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-09-12'))->create(['points' => 4]);

        $earnings = $this->earnings();

        $this->assertSame(4.0, $earnings->points);
        $this->assertSame(0, $earnings->retainer);
        $this->assertSame(2500, $earnings->monthlyBonus);
    }

    public function test_points_earned_after_the_agreement_ended_are_not_paid(): void
    {
        IncentiveAgreement::factory()->for($this->john)->create(['starts_on' => '2026-01-01', 'ends_on' => '2026-09-15']);
        PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-09-15'))->create(['points' => 6]);
        PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-09-16'))->create(['points' => 30]);

        $this->assertSame(6.0, $this->earnings()->points);
    }

    public function test_provisional_points_outside_the_agreement_are_not_counted_either(): void
    {
        IncentiveAgreement::factory()->for($this->john)->create(['starts_on' => '2026-09-10']);
        PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-09-03'))->provisional()->create(['points' => 9]);

        $this->assertSame(0.0, $this->earnings()->provisionalPoints);
    }

    public function test_an_agreement_added_later_but_backdated_pays_the_points_it_covers(): void
    {
        PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-09-03'))->create(['points' => 20]);
        $this->assertSame(0.0, $this->earnings()->total(), 'No agreement yet: nothing is paid.');

        IncentiveAgreement::factory()->for($this->john)->create(['starts_on' => '2026-09-01']);

        $this->assertSame(20.0, $this->earnings()->points);
        $this->assertSame(7500 + 2500 + 1500, (int) $this->earnings()->total());
    }

    public function test_a_paid_month_is_not_paid_again_when_agreement_dates_change_afterwards(): void
    {
        $accounts = User::factory()->withRole(Role::Accounts)->create();
        $agreement = IncentiveAgreement::factory()->for($this->john)->create(['starts_on' => '2026-01-01']);
        PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-08-05'))->create(['points' => 15]);

        $this->travelTo(CarbonImmutable::parse('2026-09-02 09:00'));
        $statements = app(Statements::class);
        $statement = $statements->draft($this->john, CarbonImmutable::parse('2026-08-01'));
        $statement = $statements->setCompliance($statement, ['reports' => true, 'training' => true, 'follow_up' => true, 'support' => true]);
        $statements->approve($statement, $accounts);
        $statements->markPaid($statement->fresh(), $accounts, 'BANK-1');

        $agreement->update(['starts_on' => '2026-08-20']);

        $september = $this->earnings();
        $this->assertSame([], $september->adjustmentLines, 'August was paid on the agreement as it stood; no recovery for a later date change.');
        $this->assertSame(0.0, $september->adjustments);
    }

    public function test_expected_pay_does_not_drop_just_because_a_draft_statement_was_generated(): void
    {
        IncentiveAgreement::factory()->for($this->john)->create(['starts_on' => '2026-01-01']);
        PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-09-03'))->create(['points' => 20]);
        $before = app(MonthlyEarnings::class)->expectedTotal($this->john, CarbonImmutable::parse('2026-09-01'));

        app(Statements::class)->draft($this->john, CarbonImmutable::parse('2026-09-01'));

        $this->assertSame(7500 + 2500 + 1500, (int) $before, 'Retainer, monthly bonus and one weekly bonus.');
        $this->assertSame($before, app(MonthlyEarnings::class)->expectedTotal($this->john, CarbonImmutable::parse('2026-09-01')));
    }

    public function test_expected_pay_drops_the_retainer_and_bonuses_once_accounts_marks_the_month_not_compliant(): void
    {
        IncentiveAgreement::factory()->for($this->john)->create(['starts_on' => '2026-01-01']);
        PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-09-03'))->create(['points' => 20]);
        $statements = app(Statements::class);
        $statement = $statements->draft($this->john, CarbonImmutable::parse('2026-09-01'));

        $statements->setCompliance($statement, ['reports' => true, 'training' => false, 'follow_up' => true, 'support' => true]);

        $this->assertSame(0.0, app(MonthlyEarnings::class)->expectedTotal($this->john, CarbonImmutable::parse('2026-09-01')));
    }

    public function test_an_approved_month_shows_its_frozen_statement_total(): void
    {
        $accounts = User::factory()->withRole(Role::Accounts)->create();
        IncentiveAgreement::factory()->for($this->john)->create(['starts_on' => '2026-01-01']);
        $entry = PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-09-03'))->create(['points' => 20]);
        $statements = app(Statements::class);
        $statement = $statements->setCompliance($statements->draft($this->john, CarbonImmutable::parse('2026-09-01')), ['reports' => true, 'training' => true, 'follow_up' => true, 'support' => true]);
        $statements->approve($statement, $accounts);

        $entry->update(['points' => 40]);

        $this->assertSame(11500.0, app(MonthlyEarnings::class)->expectedTotal($this->john, CarbonImmutable::parse('2026-09-01')));
    }

    public function test_a_property_added_later_by_the_same_owner_goes_to_the_salesperson_who_referred_the_owner(): void
    {
        IncentiveAgreement::factory()->for($this->mary)->create(['starts_on' => '2026-01-01']);

        $this->provider(['account_id' => 'H-1', 'ref_code' => $this->mary->referralCode->code, 'property_name' => 'First Lodge']);
        $this->sync();

        $this->provider(['account_id' => 'H-1', 'ref_code' => null, 'property_name' => 'Second Lodge']);
        $this->provider(['account_id' => 'H-1', 'ref_code' => $this->john->referralCode->code, 'property_name' => 'Third Lodge']);
        $this->sync();

        $this->assertSame(
            [$this->mary->id],
            Onboarding::query()->pluck('user_id')->unique()->values()->all(),
            'Every property of the owner belongs to the salesperson the owner was linked to.',
        );
        $this->assertSame($this->mary->id, PartnerAccount::sole()->user_id);
    }

    public function test_a_fired_salespersons_properties_become_unattributed_and_management_is_told(): void
    {
        $admin = User::factory()->withRole(Role::SuperAdmin)->create();
        $this->provider(['account_id' => 'H-3', 'ref_code' => $this->mary->referralCode->code, 'property_name' => 'First Lodge']);
        $this->sync();

        app(ChangeAccountStatus::class)->terminate($this->mary, $admin, 'other');

        $this->provider(['account_id' => 'H-3', 'ref_code' => null, 'property_name' => 'Late Lodge']);
        $this->sync();

        $this->assertSame([null], Onboarding::query()->pluck('user_id')->unique()->values()->all(), 'Neither the old nor the new property is credited to the fired salesperson.');
        $this->assertNull(PartnerAccount::sole()->user_id);
        $this->assertSame(1, AuditEvent::query()->where('action', 'onboarding.released_unattributed')->count());
        $this->assertTrue($admin->notifications()->get()->contains(fn ($n) => ($n->data['title'] ?? '') === 'Properties need a salesperson'));
    }

    public function test_a_property_that_already_earned_credit_stays_with_the_fired_salesperson_but_later_ones_do_not(): void
    {
        $admin = User::factory()->withRole(Role::SuperAdmin)->create();
        $this->provider(['account_id' => 'H-4', 'ref_code' => $this->mary->referralCode->code, 'property_name' => 'Paid Lodge']);
        $this->provider(['account_id' => 'H-5', 'ref_code' => $this->mary->referralCode->code, 'property_name' => 'Waiting Lodge']);
        $this->sync();
        $earned = Onboarding::query()->where('property_name', 'Paid Lodge')->sole();
        PointEntry::factory()->for($this->mary)->create(['partner_account_id' => $earned->partner_account_id, 'points' => 5]);

        app(ChangeAccountStatus::class)->terminate($this->mary, $admin, 'other');
        $this->provider(['account_id' => 'H-4', 'ref_code' => null, 'property_name' => 'Later Lodge']);
        $this->sync();

        $this->assertSame($this->mary->id, $earned->fresh()->user_id, 'Credit already earned stays on the salesperson.');
        $this->assertNull(Onboarding::query()->where('property_name', 'Waiting Lodge')->value('user_id'));
        $this->assertNull(Onboarding::query()->where('property_name', 'Later Lodge')->value('user_id'), 'Properties that come later are unattributed.');
    }

    public function test_earlier_properties_with_no_salesperson_follow_the_owner_once_one_is_known(): void
    {
        $this->provider(['account_id' => 'H-2', 'ref_code' => null, 'property_name' => 'Unclaimed Lodge']);
        $this->sync();
        $this->assertNull(Onboarding::sole()->user_id);

        $this->provider(['account_id' => 'H-2', 'ref_code' => $this->mary->referralCode->code, 'property_name' => 'Referred Lodge']);
        $this->sync();

        $this->assertSame([$this->mary->id], Onboarding::query()->pluck('user_id')->unique()->values()->all());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function provider(array $attributes): SandboxProvider
    {
        return SandboxProvider::factory()->create([
            'status' => 'active',
            'approved_at' => now()->subDay(),
            'active_at' => now(),
            'property_type' => 'hotel',
            'inventory_count' => 20,
            ...$attributes,
        ]);
    }

    private function sync(): void
    {
        app(SyncOnboardings::class)->handle('full');
    }
}
