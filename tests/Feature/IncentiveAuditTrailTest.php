<?php

namespace Tests\Feature;

use App\Actions\IssueReferralCode;
use App\Actions\SavePaymentDetail;
use App\Actions\SyncOnboardings;
use App\Enums\Role;
use App\Incentives\AccountPoints;
use App\Incentives\ChecklistItem;
use App\Incentives\Statements;
use App\Livewire\Admin\Incentives;
use App\Models\AuditEvent;
use App\Models\IncentiveAgreement;
use App\Models\PartnerAccount;
use App\Models\PointEntry;
use App\Models\SandboxProvider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class IncentiveAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private User $john;

    private User $admin;

    private User $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tourlast.source' => 'sandbox']);
        $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00'));
        $this->john = User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);
        $this->admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $this->accounts = User::factory()->withRole(Role::Accounts)->create();
        app(IssueReferralCode::class)->handle($this->john);
        $this->john->refresh();
    }

    private function event(string $action): AuditEvent
    {
        return AuditEvent::query()->where('action', $action)->sole();
    }

    public function test_every_step_of_a_payout_statement_records_who_did_it(): void
    {
        IncentiveAgreement::factory()->for($this->john)->create(['starts_on' => '2026-01-01']);
        PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-08-05'))->create(['points' => 20]);
        $statements = app(Statements::class);

        $this->actingAs($this->accounts);
        $statement = $statements->draft($this->john, CarbonImmutable::parse('2026-08-01'));
        $statement = $statements->setCompliance($statement, ['reports' => true, 'training' => true, 'follow_up' => true, 'support' => true]);
        $statements->approve($statement, $this->accounts);
        $statements->markPaid($statement->fresh(), $this->accounts, 'BANK-9');

        foreach (['statement.compliance_set', 'statement.approved', 'statement.paid'] as $action) {
            $event = $this->event($action);
            $this->assertSame($this->accounts->id, $event->user_id, $action);
            $this->assertSame($statement->getMorphClass(), $event->subject_type, $action);
            $this->assertSame($statement->id, $event->subject_id, $action);
        }

        $this->assertStringContainsString('BANK-9', $this->event('statement.paid')->summary);
        $this->assertStringContainsString('John Doe', $this->event('statement.approved')->summary);
        $this->assertSame(1, AuditEvent::query()->where('action', 'statement.drafted')->count());
    }

    public function test_agreement_changes_are_logged_with_before_and_after_dates(): void
    {
        Livewire::actingAs($this->admin)->test(Incentives::class)
            ->call('openAgreement')
            ->set('agreement.user_id', (string) $this->john->id)
            ->set('agreement.starts_on', '2026-09-01')
            ->call('saveAgreement')
            ->assertHasNoErrors();

        $created = $this->event('agreement.created');
        $this->assertSame($this->admin->id, $created->user_id);

        $agreement = IncentiveAgreement::sole();
        Livewire::actingAs($this->admin)->test(Incentives::class)
            ->call('openAgreement', $agreement->id)
            ->set('agreement.ends_on', '2026-12-31')
            ->call('saveAgreement')
            ->assertHasNoErrors();

        $updated = $this->event('agreement.updated');
        $this->assertSame($this->admin->id, $updated->user_id);
        $this->assertSame([null, '2026-12-31'], $updated->changes['ends_on']);
    }

    public function test_verifying_an_account_and_failing_its_review_are_logged(): void
    {
        IncentiveAgreement::factory()->for($this->john)->create(['starts_on' => '2026-01-01']);
        SandboxProvider::factory()->create([
            'ref_code' => $this->john->referralCode->code,
            'status' => 'active',
            'approved_at' => now()->subDay(),
            'active_at' => now(),
            'property_type' => 'hotel',
            'inventory_count' => 40,
            'account_id' => 'H-1',
        ]);
        app(SyncOnboardings::class)->handle('full');
        $account = PartnerAccount::sole();
        foreach (ChecklistItem::cases() as $item) {
            $account->checklistItems()->updateOrCreate(['item' => $item->value], ['completed_at' => now(), 'source' => $item->isAutomatic() ? 'auto' : 'manual']);
        }

        $this->actingAs($this->admin);
        app(AccountPoints::class)->verify($account->fresh(), $this->admin, 40, 'rooms');
        app(AccountPoints::class)->failReview($account->fresh(), $this->admin, 'Partner closed');

        $this->assertSame($this->admin->id, $this->event('account.verified')->user_id);
        $failed = $this->event('account.review_failed');
        $this->assertSame($this->admin->id, $failed->user_id);
        $this->assertStringContainsString('Partner closed', $failed->summary);
    }

    public function test_moving_a_property_to_the_owners_salesperson_is_logged_as_a_system_action(): void
    {
        $mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambui']);
        app(IssueReferralCode::class)->handle($mary);
        $mary->refresh();

        foreach ([[$mary->referralCode->code, 'First'], [null, 'Second']] as [$code, $name]) {
            SandboxProvider::factory()->create([
                'ref_code' => $code,
                'status' => 'active',
                'approved_at' => now()->subDay(),
                'active_at' => now(),
                'property_type' => 'hotel',
                'inventory_count' => 20,
                'account_id' => 'H-7',
                'property_name' => $name,
            ]);
            app(SyncOnboardings::class)->handle('full');
        }

        $event = $this->event('onboarding.attribution_inherited');
        $this->assertNull($event->user_id, 'Done by the sync, not a person.');
        $this->assertStringContainsString('Second', $event->summary);
        $this->assertStringContainsString('Mary Wambui', $event->summary);
    }

    public function test_changing_payout_details_is_logged_without_the_account_number(): void
    {
        $this->actingAs($this->john);
        app(SavePaymentDetail::class)->handle($this->john, ['method' => 'bank', 'bank_name' => 'KCB', 'bank_branch' => null, 'account_number' => '1234567890', 'account_name' => 'John Doe']);

        $event = $this->event('payment_details.changed');
        $this->assertSame($this->john->id, $event->user_id);
        $this->assertStringNotContainsString('1234567890', $event->summary.json_encode($event->changes));
    }
}
