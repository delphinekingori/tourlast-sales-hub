<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Onboarding;
use App\Models\User;
use App\Support\HubOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_dashboard_shows_live_numbers_the_trend_and_the_top_salesperson(): void
    {
        $admin = User::factory()->withRole(Role::SuperAdmin)->create();
        $seller = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Wanjiru Top']);

        Onboarding::factory()->forSalesperson($seller)->active(now()->startOfMonth()->addDay())->count(3)->create();
        Onboarding::factory()->forSalesperson($seller)->active(now()->subMonthNoOverflow()->startOfMonth()->addDay())->create();

        $overview = (new HubOverview)->onboarding();
        $this->assertSame(3, $overview['month']);
        $this->assertSame(1, $overview['lastMonth']);
        $this->assertSame(2, $overview['delta']);
        $this->assertCount(6, $overview['trend']);

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Went live this month')
            ->assertSee('▲ 2 more than last month')
            ->assertSee('Partners going live')
            ->assertSee('Where signups are now')
            ->assertSee('Top salespeople this month')
            ->assertSee('Wanjiru Top');
    }

    public function test_only_people_who_handle_money_and_travel_see_those_cards(): void
    {
        $this->actingAs(User::factory()->withRole(Role::Salesperson)->create())->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Money waiting')
            ->assertDontSee('Travel this month');

        $this->actingAs(User::factory()->withRole(Role::Accounts)->create())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Money waiting')
            ->assertDontSee('Travel this month');

        $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Money waiting')
            ->assertSee('Travel this month');
    }
}
