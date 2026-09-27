<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Livewire\Team\Index;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sales_manager_can_only_invite_salespeople(): void
    {
        Mail::fake();
        $manager = User::factory()->withRole(Role::SalesManager)->create();

        Livewire::actingAs($manager)->test(Index::class)
            ->set('invite', ['name' => 'Eve', 'email' => 'eve@tourlast.com', 'phone' => '', 'role' => Role::SalesAdmin->value, 'region' => ''])
            ->call('sendInvite')
            ->assertHasErrors('invite.role');

        Livewire::actingAs($manager)->test(Index::class)
            ->set('invite', ['name' => 'Eve', 'email' => 'eve@tourlast.com', 'phone' => '', 'role' => Role::Salesperson->value, 'region' => ''])
            ->call('sendInvite')
            ->assertHasNoErrors();

        $this->assertSame(Role::Salesperson, Invitation::sole()->role);
    }

    public function test_a_sales_admin_cannot_create_super_admins(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();

        Livewire::actingAs($admin)->test(Index::class)
            ->set('invite', ['name' => 'Eve', 'email' => 'eve@tourlast.com', 'phone' => '', 'role' => Role::SuperAdmin->value, 'region' => ''])
            ->call('sendInvite')
            ->assertHasErrors('invite.role');
    }

    public function test_a_sales_manager_cannot_cancel_an_admin_invitation(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $invitation = Invitation::factory()->create(['role' => Role::Hr]);

        Livewire::actingAs($manager)->test(Index::class)->call('revokeInvite', $invitation->id)->assertForbidden();

        $this->assertTrue($invitation->fresh()->isUsable());
    }

    public function test_a_sales_admin_can_change_a_role_and_region(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $person = User::factory()->withRole(Role::Hr)->create(['name' => 'Peter Kamau']);

        Livewire::actingAs($admin)->test(Index::class)
            ->call('openEdit', $person->id)
            ->set('edit.role', Role::Salesperson->value)
            ->set('edit.region', 'Coast')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $person->refresh();
        $this->assertTrue($person->hasRole(Role::Salesperson->value));
        $this->assertFalse($person->hasRole(Role::Hr->value));
        $this->assertSame('Coast', $person->region);
        $this->assertStringStartsWith('TL-PETER-', $person->referralCode->code);
    }

    public function test_a_sales_manager_cannot_edit_accounts(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $person = User::factory()->withRole(Role::Salesperson)->create();

        Livewire::actingAs($manager)->test(Index::class)->call('openEdit', $person->id)->assertForbidden();
    }

    public function test_nobody_can_edit_their_own_account_from_the_team_page(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();

        Livewire::actingAs($admin)->test(Index::class)->call('openEdit', $admin->id)->assertForbidden();
    }

    public function test_a_sales_admin_cannot_edit_a_super_admin(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $superAdmin = User::factory()->withRole(Role::SuperAdmin)->create();

        Livewire::actingAs($admin)->test(Index::class)->call('openEdit', $superAdmin->id)->assertForbidden();
    }

    public function test_the_team_list_can_be_searched(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambui']);
        User::factory()->withRole(Role::Salesperson)->create(['name' => 'Peter Kamau']);

        Livewire::actingAs($admin)->test(Index::class)
            ->set('search', 'Wambui')
            ->assertSee('Mary Wambui')
            ->assertDontSee('Peter Kamau');
    }
}
