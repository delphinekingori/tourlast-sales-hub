<?php

namespace Tests\Feature\Travel;

use App\Enums\Role;
use App\Models\FlightBooking;
use App\Models\PackageBooking;
use App\Models\ProviderContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TravelDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_salesperson_sees_their_figures_and_sales_actions(): void
    {
        $booking = PackageBooking::factory()->create(['amount_total' => 45000]);
        $owner = $booking->salesperson;
        FlightBooking::factory()->create(['salesperson_id' => $owner->id, 'booked_at' => now()]);
        $contract = ProviderContract::factory()->endingIn(10)->create(['travel_provider_id' => $booking->package->travel_provider_id]);

        $this->actingAs($owner)->get(route('travel.dashboard'))
            ->assertOk()
            ->assertSee('My sales actions')
            ->assertSee('Confirm customer booking')
            ->assertSee($booking->reference)
            ->assertSee('Provider contract renewal')
            ->assertSee($contract->contract_number)
            ->assertDontSee('Markup')
            ->assertDontSee('Travel salespeople this month');
    }

    public function test_sales_admin_sees_the_whole_team_and_markup(): void
    {
        $travel = User::factory()->withRole(Role::TravelSalesperson)->create(['name' => 'Aisha Njeri']);
        FlightBooking::factory()->create(['salesperson_id' => $travel->id, 'booked_at' => now(), 'total_amount' => 30000]);

        $this->actingAs(User::factory()->withRole(Role::SalesAdmin)->create())
            ->get(route('travel.dashboard', ['scope' => 'team']))
            ->assertOk()
            ->assertSee('Travel salespeople this month')
            ->assertSee('Aisha Njeri')
            ->assertSee('Markup')
            ->assertSee('Package approvals');
    }

    public function test_travel_salesperson_cannot_switch_to_the_team_view(): void
    {
        $travel = User::factory()->withRole(Role::TravelSalesperson)->create();
        User::factory()->withRole(Role::TravelSalesperson)->create(['name' => 'Someone Else']);

        $this->actingAs($travel)->get(route('travel.dashboard', ['scope' => 'team']))
            ->assertOk()
            ->assertDontSee('Travel salespeople this month')
            ->assertDontSee('Someone Else');
    }
}
