<?php

namespace Tests\Feature;

use App\Actions\IssueReferralCode;
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

    public function test_a_super_admin_can_change_a_referral_code(): void
    {
        $super = User::factory()->withRole(Role::SuperAdmin)->create();
        $person = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Peter Kamau']);
        app(IssueReferralCode::class)->handle($person);
        $original = $person->referralCode->code;

        Livewire::actingAs($super)->test(Index::class)
            ->call('openEdit', $person->id)
            ->set('edit.role', Role::Salesperson->value)
            ->set('edit.region', 'Coast')
            ->set('edit.ref_code', 'tl-peter-9999')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $person->refresh();
        $this->assertSame('TL-PETER-9999', $person->referralCode->code);
        $this->assertNotSame($original, $person->referralCode->code);
        $this->assertSame('Coast', $person->region);
    }

    public function test_a_referral_code_already_used_by_someone_else_is_rejected(): void
    {
        $super = User::factory()->withRole(Role::SuperAdmin)->create();
        $taken = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambui']);
        app(IssueReferralCode::class)->handle($taken);
        $person = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Peter Kamau']);
        app(IssueReferralCode::class)->handle($person);

        Livewire::actingAs($super)->test(Index::class)
            ->call('openEdit', $person->id)
            ->set('edit.role', Role::Salesperson->value)
            ->set('edit.ref_code', $taken->referralCode->code)
            ->call('saveEdit')
            ->assertHasErrors('edit.ref_code');

        $this->assertSame('TL-PETER-', substr($person->referralCode->fresh()->code, 0, 9));
    }

    public function test_a_sales_admin_cannot_change_a_referral_code(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $person = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Peter Kamau']);
        app(IssueReferralCode::class)->handle($person);
        $original = $person->referralCode->code;

        Livewire::actingAs($admin)->test(Index::class)
            ->call('openEdit', $person->id)
            ->set('edit.role', Role::Salesperson->value)
            ->set('edit.region', 'Coast')
            ->set('edit.ref_code', 'TL-HACK-1234')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertSame($original, $person->referralCode->fresh()->code);
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
