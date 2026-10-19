<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\Role;
use App\Livewire\Accounts\Index;
use App\Livewire\Accounts\Show;
use App\Models\PartnerAccount;
use App\Models\PointEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AccountHistoryReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    private User $mary;

    private User $hr;

    private PartnerAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambui']);
        $this->hr = User::factory()->withRole(Role::Hr)->create();
        $this->account = PartnerAccount::factory()->create([
            'user_id' => $this->mary->id,
            'legal_name' => 'Coral Bay Hospitality Ltd',
            'activation_date' => '2026-09-01 10:00:00',
            'activation_inventory' => 30,
        ]);
        PointEntry::factory()->create(['partner_account_id' => $this->account->id, 'user_id' => $this->mary->id]);
    }

    public function test_the_permission_is_given_to_hr_and_the_roles_that_already_see_all_accounts(): void
    {
        foreach ([Role::Hr, Role::SalesAdmin, Role::SalesManager, Role::Accounts, Role::SuperAdmin] as $role) {
            $this->assertContains(Permission::ViewAccountHistory, $role->permissions(), $role->value);
        }

        foreach ([Role::Salesperson, Role::TravelSalesperson] as $role) {
            $this->assertNotContains(Permission::ViewAccountHistory, $role->permissions(), $role->value);
        }
    }

    public function test_hr_sees_everyones_accounts_and_the_navigation_link(): void
    {
        $this->actingAs($this->hr)->get('/')->assertSee('Partner Accounts');

        Livewire::actingAs($this->hr)->test(Index::class)
            ->assertOk()
            ->assertSee('Coral Bay Hospitality Ltd')
            ->assertSee('Mary Wambui')
            ->set('owner', (string) $this->mary->id)
            ->assertSee('Coral Bay Hospitality Ltd')
            ->set('period', '2026-09')
            ->assertSee('Coral Bay Hospitality Ltd')
            ->set('period', '2026-08')
            ->assertDontSee('Coral Bay Hospitality Ltd');
    }

    public function test_hr_reads_the_points_ledger_without_any_action_buttons(): void
    {
        $this->actingAs($this->hr)->get(route('accounts.show', $this->account))
            ->assertOk()
            ->assertSee('Points history')
            ->assertDontSee('Verify &amp; approve points', false)
            ->assertDontSee('Record inventory change')
            ->assertDontSee('Merge into another Account')
            ->assertDontSee('Fail 14-day review');
    }

    public function test_hr_cannot_run_any_mutating_action(): void
    {
        $attempts = [
            fn ($component) => $component->call('openVerify'),
            fn ($component) => $component->call('toggleItem', 'agreement_signed'),
            fn ($component) => $component->call('openInventory'),
            fn ($component) => $component->call('verifySnapshot', 1),
            fn ($component) => $component->set('failReason', 'Not kept live and booking ready')->call('failReview'),
            fn ($component) => $component->call('merge'),
            fn ($component) => $component->set('evidenceItem', 'agreement_signed')->call('uploadEvidence'),
        ];

        foreach ($attempts as $attempt) {
            $attempt(Livewire::actingAs($this->hr)->test(Show::class, ['account' => $this->account]))->assertForbidden();
        }

        $this->assertFalse($this->account->fresh()->isVerified());
    }

    public function test_a_salesperson_cannot_see_another_salespersons_accounts(): void
    {
        $other = User::factory()->withRole(Role::Salesperson)->create();

        $this->actingAs($other)->get(route('accounts.show', $this->account))->assertForbidden();

        Livewire::actingAs($other)->test(Index::class)->assertDontSee('Coral Bay Hospitality Ltd');
    }

    public function test_the_api_list_is_open_to_hr_but_not_to_other_salespeople(): void
    {
        $this->assertTrue($this->hr->can('view-account', $this->account));
        $this->assertFalse(User::factory()->withRole(Role::Salesperson)->create()->can('view-account', $this->account));
    }
}
