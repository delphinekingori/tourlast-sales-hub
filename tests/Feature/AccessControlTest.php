<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{Role, int}>
     */
    public static function teamPageAccess(): array
    {
        return [
            'super admin' => [Role::SuperAdmin, 200],
            'sales admin' => [Role::SalesAdmin, 200],
            'sales manager' => [Role::SalesManager, 200],
            'salesperson' => [Role::Salesperson, 403],
            'hr' => [Role::Hr, 403],
            'accounts' => [Role::Accounts, 403],
        ];
    }

    #[DataProvider('teamPageAccess')]
    public function test_only_admins_and_managers_can_open_users_and_invites(Role $role, int $expectedStatus): void
    {
        $this->actingAs(User::factory()->withRole($role)->create())
            ->get(route('team.index'))
            ->assertStatus($expectedStatus);
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function everyRole(): array
    {
        return array_combine(
            array_map(fn (Role $role): string => $role->label(), Role::cases()),
            array_map(fn (Role $role): array => [$role], Role::cases()),
        );
    }

    #[DataProvider('everyRole')]
    public function test_every_role_can_open_home_and_profile(Role $role): void
    {
        $user = User::factory()->withRole($role)->create();

        // Travel salespeople land on the Travel dashboard instead.
        $home = $role === Role::TravelSalesperson ? route('travel.dashboard') : '/';

        $this->actingAs($user)->get($home)->assertOk();
        $this->actingAs($user)->get(route('profile'))->assertOk();
    }

    public function test_salespeople_do_not_see_admin_navigation(): void
    {
        $this->actingAs(User::factory()->withRole(Role::Salesperson)->create())
            ->get('/')
            ->assertSee('My Progress')
            ->assertDontSee('Users &amp; Invites', false)
            ->assertDontSee('Partner Register');
    }

    public function test_hr_sees_the_partner_register_but_not_sales_screens(): void
    {
        $this->actingAs(User::factory()->withRole(Role::Hr)->create())
            ->get('/')
            ->assertSee('Partner Register')
            ->assertDontSee('My Progress')
            ->assertDontSee('Team Performance')
            ->assertDontSee('Leads');
    }
}
