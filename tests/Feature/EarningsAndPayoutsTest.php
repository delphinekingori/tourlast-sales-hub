<?php

namespace Tests\Feature;

use App\Actions\IssueReferralCode;
use App\Enums\Role;
use App\Incentives\AccountPoints;
use App\Incentives\MonthlyEarnings;
use App\Incentives\Statements;
use App\Livewire\Payouts\Index as Payouts;
use App\Models\ExpenseClaim;
use App\Models\IncentiveAgreement;
use App\Models\PartnerAccount;
use App\Models\PayoutStatement;
use App\Models\PointEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EarningsAndPayoutsTest extends TestCase
{
    use RefreshDatabase;

    private User $john;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00'));
        $this->john = User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);
        app(IssueReferralCode::class)->handle($this->john);
        IncentiveAgreement::factory()->for($this->john)->create(['starts_on' => '2026-01-01']);
    }

    public function test_expected_earnings_use_approved_points_and_approved_claims(): void
    {
        PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-09-03'))->create(['points' => 8]);
        PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-09-10'))->create(['points' => 19]);
        PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-09-16'))->create(['points' => 4.5]);
        PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-09-23'))->provisional()->create(['points' => 6]);
        PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-09-23'))->create(['points' => 5, 'status' => 'cancelled']);
        ExpenseClaim::factory()->for($this->john)->create(['type' => 'airtime', 'amount' => 400, 'status' => 'approved', 'current_step' => null]);
        ExpenseClaim::factory()->for($this->john)->create(['amount' => 1500, 'approved_amount' => 1200, 'status' => 'approved', 'current_step' => null]);
        ExpenseClaim::factory()->for($this->john)->create(['amount' => 999, 'status' => 'pending']);

        $earnings = app(MonthlyEarnings::class)->for($this->john, now()->toImmutable());

        $this->assertSame(31.5, $earnings->points);
        $this->assertSame(6.0, $earnings->provisionalPoints);
        $this->assertSame(23100.0, $earnings->total());

        $this->actingAs($this->john)->get(route('earnings.mine'))
            ->assertOk()
            ->assertSee('KES 23,100')
            ->assertSee('Up to KES 25,600')
            ->assertSee('4.5 more points');
    }

    public function test_without_an_agreement_nothing_is_paid(): void
    {
        IncentiveAgreement::query()->delete();
        PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-09-03'))->create(['points' => 40]);

        $this->assertSame(0.0, app(MonthlyEarnings::class)->for($this->john, now()->toImmutable())->total());
        $this->actingAs($this->john)->get(route('earnings.mine'))->assertSee('No incentive agreement');
    }

    public function test_a_statement_is_frozen_on_approval_and_later_cancellations_are_recovered_next_month(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $accounts = User::factory()->withRole(Role::Accounts)->create();
        $account = PartnerAccount::factory()->create(['user_id' => $this->john->id, 'activation_date' => '2026-08-28 10:00', 'activation_inventory' => 90, 'qualification_status' => 'verified']);
        app(AccountPoints::class)->reconcile($account);
        PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-08-05'))->create(['points' => 15]);

        $this->travelTo(CarbonImmutable::parse('2026-09-02 09:00'));
        $statements = app(Statements::class);
        $statement = $statements->generate(CarbonImmutable::parse('2026-08-01'))->sole();
        $this->assertSame(20.0, $statement->points);
        $this->assertSame(0, $statement->retainer, 'Retainer waits for the reporting conditions.');

        $statement = $statements->setCompliance($statement, ['reports' => true, 'training' => true, 'follow_up' => true, 'support' => true]);
        $this->assertSame(7500, $statement->retainer);
        $statements->approve($statement, $accounts);
        $statements->markPaid($statement->fresh(), $accounts, 'BANK-1');

        $this->travelTo(CarbonImmutable::parse('2026-09-05 09:00'));
        app(AccountPoints::class)->failReview($account->fresh(), $admin, 'Partner opted out during review');

        $september = app(MonthlyEarnings::class)->for($this->john, CarbonImmutable::parse('2026-09-01'));
        $this->assertCount(1, $september->adjustmentLines);
        $this->assertSame(-7500.0, $september->adjustments, 'Retainer 7,500 lost; monthly bonus stays 2,500 at 15 points.');
        $this->assertSame(2500, PayoutStatement::sole()->monthly_bonus);

        $next = $statements->approve($statements->draft($this->john, CarbonImmutable::parse('2026-09-01')), $accounts);
        $this->assertSame(-7500.0, $next->adjustments);
        $this->assertSame(0.0, app(MonthlyEarnings::class)->for($this->john, CarbonImmutable::parse('2026-10-01'))->adjustments, 'A recovery is taken only once.');
    }

    public function test_paying_a_statement_marks_its_reimbursements_paid(): void
    {
        $accounts = User::factory()->withRole(Role::Accounts)->create();
        $claim = ExpenseClaim::factory()->for($this->john)->create(['status' => 'approved', 'current_step' => null, 'month' => '2026-08-01', 'travel_date' => '2026-08-10']);
        $statements = app(Statements::class);

        $statement = $statements->approve($statements->draft($this->john, CarbonImmutable::parse('2026-08-01')), $accounts);
        $this->assertSame(800.0, $statement->transport);
        $statements->markPaid($statement, $accounts, 'BANK-9');

        $this->assertSame('paid', $claim->fresh()->status);
        $this->assertSame('BANK-9', $claim->fresh()->payment_reference);
    }

    public function test_only_accounts_can_approve_and_pay_statements(): void
    {
        $statement = app(Statements::class)->draft($this->john, CarbonImmutable::parse('2026-08-01'));
        $hr = User::factory()->withRole(Role::Hr)->create();
        $accounts = User::factory()->withRole(Role::Accounts)->create();

        Livewire::actingAs($hr)->test(Payouts::class)->call('view', $statement->id)->call('approve')->assertForbidden();
        Livewire::actingAs($accounts)->test(Payouts::class)->call('view', $statement->id)->call('approve')->assertHasNoErrors();
        $this->assertSame('approved', $statement->fresh()->status);

        $this->actingAs($this->john)->get(route('payouts.index'))->assertForbidden();
        $this->actingAs($accounts)->get(route('downloads.statement', $statement))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs(User::factory()->withRole(Role::Salesperson)->create())->get(route('downloads.statement', $statement))->assertForbidden();
    }

    public function test_managers_see_points_but_not_pay_while_hr_sees_pay(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $hr = User::factory()->withRole(Role::Hr)->create();

        $this->actingAs($manager)->get(route('earnings.member', $this->john))->assertForbidden();
        $this->actingAs($manager)->get(route('team.performance'))->assertOk()->assertDontSee('Expected pay');
        $this->actingAs($hr)->get(route('earnings.member', $this->john))->assertOk()->assertSee('John Doe');
    }
}
