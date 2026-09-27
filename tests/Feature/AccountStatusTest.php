<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Livewire\Auth\Login;
use App\Livewire\Team\Index;
use App\Models\Lead;
use App\Models\User;
use App\Models\UserStatusChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AccountStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sales_manager_can_suspend_and_reinstate_a_salesperson(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create(['name' => 'David Otieno']);
        $john = User::factory()->withRole(Role::Salesperson)->create();

        Livewire::actingAs($manager)->test(Index::class)
            ->call('openAccountAction', $john->id, 'suspend')
            ->set('account.reason', 'investigation')
            ->set('account.until', now()->addWeek()->toDateString())
            ->set('account.notes', 'Client complaint being reviewed.')
            ->call('saveAccountAction')
            ->assertHasNoErrors();

        $john->refresh();
        $this->assertSame(AccountStatus::Suspended, $john->accountStatus());
        $this->assertFalse($john->is_active);
        $this->assertSame(now()->addWeek()->toDateString(), $john->suspended_until->toDateString());

        $change = UserStatusChange::sole();
        $this->assertSame([AccountStatus::Active, AccountStatus::Suspended, 'investigation', $manager->id], [$change->from_status, $change->to_status, $change->reason, $change->changed_by]);

        // Suspended people can't sign in, and are told why.
        $this->post('/logout');
        Livewire::test(Login::class)
            ->set('email', $john->email)->set('password', 'password')
            ->call('login')
            ->assertHasErrors(['email' => 'This account is suspended until '.now()->addWeek()->format('j M Y').'. Contact your Sales Manager.']);

        Livewire::actingAs($manager)->test(Index::class)
            ->call('openAccountAction', $john->id, 'reinstate')
            ->call('saveAccountAction')
            ->assertHasNoErrors();

        $this->assertSame(AccountStatus::Active, $john->fresh()->accountStatus());
        $this->assertTrue($john->fresh()->is_active);
    }

    public function test_a_sales_manager_can_fire_a_salesperson_but_only_an_admin_can_reinstate(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $lead = Lead::factory()->for($john)->create();

        Livewire::actingAs($manager)->test(Index::class)
            ->call('openAccountAction', $john->id, 'terminate')
            ->set('account.reason', 'misconduct')
            ->call('saveAccountAction')
            ->assertHasErrors('account.confirm')
            ->set('account.confirm', true)
            ->call('saveAccountAction')
            ->assertHasNoErrors();

        $this->assertSame(AccountStatus::Terminated, $john->fresh()->accountStatus());
        $this->assertModelExists($lead);

        Livewire::actingAs($manager)->test(Index::class)->call('openAccountAction', $john->id, 'reinstate')->assertForbidden();

        Livewire::actingAs($admin)->test(Index::class)->call('openAccountAction', $john->id, 'reinstate')->call('saveAccountAction');
        $this->assertSame(AccountStatus::Active, $john->fresh()->accountStatus());
    }

    public function test_a_sales_manager_cannot_delete_anyone(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $john = User::factory()->withRole(Role::Salesperson)->create();

        Livewire::actingAs($manager)->test(Index::class)->call('openAccountAction', $john->id, 'delete')->assertForbidden();
        $this->assertModelExists($john);
    }

    public function test_a_sales_manager_can_only_act_on_salespeople(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();

        foreach ([Role::Hr, Role::Accounts, Role::SalesManager, Role::SalesAdmin, Role::SuperAdmin] as $role) {
            $target = User::factory()->withRole($role)->create();
            Livewire::actingAs($manager)->test(Index::class)->call('openAccountAction', $target->id, 'suspend')->assertForbidden();
            Livewire::actingAs($manager)->test(Index::class)->call('openAccountAction', $target->id, 'terminate')->assertForbidden();
        }
    }

    public function test_hr_and_accounts_cannot_suspend_fire_or_delete(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();

        foreach ([Role::Hr, Role::Accounts] as $role) {
            $user = User::factory()->withRole($role)->create();
            $this->assertFalse($user->can('suspend', $john));
            $this->assertFalse($user->can('terminate', $john));
            $this->assertFalse($user->can('delete', $john));
            $this->actingAs($user)->get(route('team.index'))->assertForbidden();
        }
    }

    public function test_sales_admin_and_super_admin_can_delete_accounts_without_history(): void
    {
        foreach ([Role::SalesAdmin, Role::SuperAdmin] as $role) {
            $admin = User::factory()->withRole($role)->create();
            $mistake = User::factory()->withRole(Role::Salesperson)->create();

            Livewire::actingAs($admin)->test(Index::class)
                ->call('openAccountAction', $mistake->id, 'delete')
                ->set('account.confirm', true)
                ->call('saveAccountAction')
                ->assertHasNoErrors();

            $this->assertModelMissing($mistake);
        }
    }

    public function test_accounts_with_history_cannot_be_deleted_and_must_be_fired_instead(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $john = User::factory()->withRole(Role::Salesperson)->create();
        Lead::factory()->for($john)->count(3)->create();

        Livewire::actingAs($admin)->test(Index::class)
            ->call('openAccountAction', $john->id, 'delete')
            ->assertSee("This account can't be deleted", false)
            ->assertSee('3 leads')
            ->set('account.confirm', true)
            ->call('saveAccountAction')
            ->assertHasErrors('account.confirm');

        $this->assertModelExists($john);
        $this->assertSame(3, Lead::query()->where('user_id', $john->id)->count());
    }

    public function test_nobody_can_act_on_their_own_account_and_only_super_admin_touches_super_admin(): void
    {
        $super = User::factory()->withRole(Role::SuperAdmin)->create();
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();

        $this->assertFalse($super->can('delete', $super));
        $this->assertFalse($super->can('terminate', $super));
        $this->assertFalse($admin->can('suspend', $admin));
        $this->assertFalse($admin->can('delete', $super));
        $this->assertTrue($super->can('delete', $admin));
    }

    public function test_suspensions_end_automatically_on_their_end_date(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create([
            'is_active' => false, 'account_status' => AccountStatus::Suspended, 'suspended_until' => now()->toDateString(),
        ]);
        $mary = User::factory()->withRole(Role::Salesperson)->create([
            'is_active' => false, 'account_status' => AccountStatus::Suspended, 'suspended_until' => now()->addDays(3)->toDateString(),
        ]);

        $this->artisan('hub:reinstate-suspensions')->assertSuccessful();

        $this->assertTrue($john->fresh()->is_active);
        $this->assertNull(UserStatusChange::query()->where('user_id', $john->id)->value('changed_by'));
        $this->assertFalse($mary->fresh()->is_active);
    }

    public function test_the_users_list_shows_status_and_filters_by_it(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        User::factory()->withRole(Role::Salesperson)->create(['name' => 'Fired Person', 'is_active' => false, 'account_status' => AccountStatus::Terminated]);
        User::factory()->withRole(Role::Salesperson)->create(['name' => 'Working Person']);

        Livewire::actingAs($admin)->test(Index::class)
            ->assertSee('Fired')
            ->set('status', 'terminated')
            ->assertSee('Fired Person')
            ->assertDontSee('Working Person');
    }
}
