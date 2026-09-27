<?php

namespace Tests\Feature\Api;

use App\Actions\IssueReferralCode;
use App\Enums\Role;
use App\Incentives\ChecklistItem;
use App\Incentives\Statements;
use App\Models\IncentiveAgreement;
use App\Models\PartnerAccount;
use App\Models\PointEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class IncentivesApiTest extends TestCase
{
    use MakesApiRequests, RefreshDatabase;

    private User $john;

    private User $mary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00'));
        $this->john = User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);
        $this->mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambui']);
        app(IssueReferralCode::class)->handle($this->john);
        IncentiveAgreement::factory()->for($this->john)->create(['starts_on' => '2026-01-01']);
    }

    public function test_partner_accounts_are_visible_to_their_owner_and_managers_only(): void
    {
        $mine = PartnerAccount::factory()->create(['user_id' => $this->john->id, 'legal_name' => 'Kifaru Hospitality Ltd']);
        $theirs = PartnerAccount::factory()->create(['user_id' => $this->mary->id]);
        PointEntry::factory()->for($this->john)->create(['partner_account_id' => $mine->id, 'points' => 3]);

        $this->api($this->john, ['incentives:read'])->getJson('/api/v1/partner-accounts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.legal_name', 'Kifaru Hospitality Ltd');
        $this->api($this->john, ['incentives:read'])->getJson('/api/v1/partner-accounts/'.$mine->id)
            ->assertOk()->assertJsonPath('data.points.0.points', 3)->assertJsonCount(count(ChecklistItem::cases()), 'data.checklist');
        $this->api($this->john, ['incentives:read'])->getJson('/api/v1/partner-accounts/'.$theirs->id)->assertForbidden();

        $this->api(User::factory()->withRole(Role::SalesManager)->create(), ['incentives:read'])->getJson('/api/v1/partner-accounts')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_only_verifiers_can_verify_and_the_checklist_must_be_complete(): void
    {
        $account = PartnerAccount::factory()->create(['user_id' => $this->john->id, 'activation_date' => now()->subDays(3)]);
        $payload = ['inventory' => 40, 'basis' => 'rooms', 'category' => 'stay'];

        $this->api(User::factory()->withRole(Role::SalesManager)->create(), ['incentives:write'])->postJson('/api/v1/partner-accounts/'.$account->id.'/verify', $payload)->assertForbidden();

        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $this->api($admin, ['incentives:write'])->postJson('/api/v1/partner-accounts/'.$account->id.'/verify', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('inventory');

        foreach (ChecklistItem::cases() as $item) {
            $account->checklistItems()->create(['item' => $item->value, 'completed_at' => now(), 'completed_by' => $admin->id, 'source' => 'manual']);
        }

        $this->api($admin, ['incentives:write'])->postJson('/api/v1/partner-accounts/'.$account->id.'/verify', $payload)
            ->assertOk()->assertJsonPath('data.verified', true)->assertJsonPath('data.activation_inventory', 40);
    }

    public function test_earnings_follow_the_view_earnings_rule(): void
    {
        $this->api($this->mary, ['incentives:read'])->getJson('/api/v1/earnings/'.$this->john->id)->assertForbidden();
        $this->api(User::factory()->withRole(Role::SalesManager)->create(), ['incentives:read'])->getJson('/api/v1/earnings/'.$this->john->id)->assertForbidden();

        $this->api(User::factory()->withRole(Role::Hr)->create(), ['incentives:read'])->getJson('/api/v1/earnings/'.$this->john->id.'?month=2026-09')
            ->assertOk()->assertJsonPath('data.month', '2026-09')->assertJsonPath('data.currency', 'KES')->assertJsonStructure(['data' => ['approved' => ['points', 'total']]]);

        $this->api($this->john, ['incentives:read'])->getJson('/api/v1/earnings/'.$this->john->id)->assertOk();
    }

    public function test_statements_are_approved_and_paid_by_accounts_only(): void
    {
        PointEntry::factory()->for($this->john)->on(CarbonImmutable::parse('2026-08-05'))->create(['points' => 20]);
        $statement = app(Statements::class)->draft($this->john, CarbonImmutable::parse('2026-08-01'));
        $accounts = User::factory()->withRole(Role::Accounts)->create();

        $this->api($this->john, ['incentives:read'])->getJson('/api/v1/statements')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.month', '2026-08');
        $this->api($this->mary, ['incentives:read'])->getJson('/api/v1/statements')->assertOk()->assertJsonCount(0, 'data');
        $this->api($this->mary, ['incentives:read'])->getJson('/api/v1/statements/'.$statement->id)->assertForbidden();
        $this->api($accounts, ['incentives:read'])->getJson('/api/v1/statements?month=2026-08')->assertOk()->assertJsonCount(1, 'data');

        $this->api(User::factory()->withRole(Role::SalesAdmin)->create(), ['incentives:write'])
            ->postJson('/api/v1/statements/'.$statement->id.'/compliance', ['compliance' => ['reports' => true, 'training' => true, 'follow_up' => true, 'support' => true]])
            ->assertOk()->assertJsonPath('data.compliant', true);

        $this->api(User::factory()->withRole(Role::Hr)->create(), ['incentives:write'])->postJson('/api/v1/statements/'.$statement->id.'/approve')->assertForbidden();

        $this->api($accounts, ['incentives:write'])->postJson('/api/v1/statements/'.$statement->id.'/pay', ['payment_reference' => 'MPESA123'])
            ->assertUnprocessable()->assertJsonValidationErrors('payment_reference');

        $this->api($accounts, ['incentives:write'])->postJson('/api/v1/statements/'.$statement->id.'/approve')->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.locked', true);
        $this->api($accounts, ['incentives:write'])->postJson('/api/v1/statements/'.$statement->id.'/pay', ['payment_reference' => 'MPESA123'])
            ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.payment_reference', 'MPESA123');

        $this->api($this->john, ['incentives:read'])->get('/api/v1/statements/'.$statement->id.'/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_missing_scope_is_refused(): void
    {
        $this->api($this->john, ['profile'])->getJson('/api/v1/statements')->assertForbidden()->assertJsonPath('required_scopes', ['incentives:read']);
        $this->api(User::factory()->withRole(Role::Accounts)->create(), ['incentives:read'])
            ->postJson('/api/v1/statements/1/approve')->assertForbidden()->assertJsonPath('required_scopes', ['incentives:write']);
    }
}
