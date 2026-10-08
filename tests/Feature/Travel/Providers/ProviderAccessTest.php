<?php

namespace Tests\Feature\Travel\Providers;

use App\Enums\Role;
use App\Models\ProviderContract;
use App\Models\ProviderIncident;
use App\Models\TravelProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProviderAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{Role, int}>
     */
    public static function roles(): array
    {
        return [
            'travel salesperson' => [Role::TravelSalesperson, 200],
            'sales admin' => [Role::SalesAdmin, 200],
            'super admin' => [Role::SuperAdmin, 200],
            'sales manager' => [Role::SalesManager, 403],
            'hr' => [Role::Hr, 403],
            'accounts' => [Role::Accounts, 403],
            'property salesperson' => [Role::Salesperson, 403],
        ];
    }

    #[DataProvider('roles')]
    public function test_only_travel_sales_can_open_the_provider_pages(Role $role, int $status): void
    {
        $contract = ProviderContract::factory()->create();
        ProviderIncident::query()->create([
            'travel_provider_id' => $contract->travel_provider_id,
            'occurred_on' => today(),
            'type' => 'customer_complaint',
            'severity' => 'low',
            'description' => 'Late pickup',
            'reported_by' => $contract->provider->owner_id,
        ]);
        $user = User::factory()->withRole($role)->create();

        foreach ([
            route('travel.providers.index'),
            route('travel.providers.create'),
            route('travel.providers.show', $contract->travel_provider_id),
            route('travel.contracts.index'),
            route('travel.contracts.show', $contract->id),
            route('travel.incidents.index'),
        ] as $url) {
            $this->actingAs($user)->get($url)->assertStatus($status);
        }
    }

    public function test_travel_salespeople_see_every_provider_but_edit_only_their_own(): void
    {
        $me = User::factory()->withRole(Role::TravelSalesperson)->create();
        $mine = TravelProvider::factory()->create(['owner_id' => $me->id, 'name' => 'Kifaru Treks']);
        $theirs = TravelProvider::factory()->create(['name' => 'Chui Expeditions']);

        $this->actingAs($me)->get(route('travel.providers.index'))->assertOk()->assertSee('Kifaru Treks')->assertSee('Chui Expeditions');
        $this->actingAs($me)->get(route('travel.providers.show', $theirs->id))->assertOk()->assertSee('Read only');
        $this->actingAs($me)->get(route('travel.providers.edit', $theirs->id))->assertForbidden();
        $this->actingAs($me)->get(route('travel.providers.edit', $mine->id))->assertOk();

        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $this->actingAs($admin)->get(route('travel.providers.edit', $theirs->id))->assertOk();
    }

    public function test_the_sidebar_shows_providers_and_contracts_to_travel_sales_only(): void
    {
        $this->actingAs(User::factory()->withRole(Role::TravelSalesperson)->create())
            ->get(route('travel.providers.index'))
            ->assertSee('Providers')
            ->assertSee('Contracts');

        $this->actingAs(User::factory()->withRole(Role::SalesManager)->create())
            ->get(route('team.performance'))
            ->assertDontSee(route('travel.providers.index'));
    }
}
