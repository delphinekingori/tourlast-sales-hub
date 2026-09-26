<?php

namespace Tests\Feature;

use App\Enums\EngagementStatus;
use App\Enums\LeadStatus;
use App\Enums\Objection;
use App\Enums\Role;
use App\Livewire\Insights\MyLosses;
use App\Livewire\Insights\Objections;
use App\Models\Lead;
use App\Models\PropertyEngagement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MyLossesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_salesperson_sees_only_their_own_losses_and_re_engagements(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $mary = User::factory()->withRole(Role::Salesperson)->create();
        Lead::factory()->for($john)->create(['business_name' => 'Johns Lost Hotel', 'status' => LeadStatus::Lost, 'objection' => Objection::Commission, 'lost_at' => now(), 'reengage_on' => now()->addMonth()->toDateString()]);
        Lead::factory()->for($john)->create(['business_name' => 'Johns Other Loss', 'status' => LeadStatus::Lost, 'objection' => Objection::OtherOta, 'competitor' => 'Booking.com', 'lost_at' => now()]);
        Lead::factory()->for($john)->create(['business_name' => 'Johns Open Lead', 'status' => LeadStatus::Contacted]);
        Lead::factory()->for($mary)->create(['business_name' => 'Marys Lost Villa', 'status' => LeadStatus::Lost, 'objection' => Objection::Timing, 'lost_at' => now()]);
        PropertyEngagement::factory()->forRep($john)->create(['name' => 'Registry Lodge John Represents', 'status' => EngagementStatus::Lost, 'objection' => Objection::HasPms, 'closed_at' => now()]);

        Livewire::actingAs($john)->test(MyLosses::class)
            ->assertSee('Johns Lost Hotel')
            ->assertSee('Johns Other Loss')
            ->assertSee('Registry Lodge John Represents')
            ->assertSee('Booking.com')
            ->assertDontSee('Johns Open Lead')
            ->assertDontSee('Marys Lost Villa')
            ->assertViewHas('losses', fn ($losses) => $losses->count() === 3)
            ->assertViewHas('upcoming', fn ($upcoming) => $upcoming->count() === 1 && $upcoming->first()['name'] === 'Johns Lost Hotel');
    }

    public function test_the_page_is_in_the_salesperson_menu_and_not_for_other_roles(): void
    {
        $this->actingAs(User::factory()->withRole(Role::Salesperson)->create())->get(route('dashboard'))->assertSee('My losses');
        $this->actingAs(User::factory()->withRole(Role::Salesperson)->create())->get(route('insights.mine'))->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Hr)->create())->get(route('insights.mine'))->assertForbidden();
    }

    public function test_the_management_page_still_shows_the_whole_team(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $mary = User::factory()->withRole(Role::Salesperson)->create();
        Lead::factory()->for($john)->create(['status' => LeadStatus::Lost, 'objection' => Objection::Commission, 'lost_at' => now()]);
        Lead::factory()->for($mary)->create(['status' => LeadStatus::Lost, 'objection' => Objection::Timing, 'lost_at' => now()]);

        Livewire::actingAs(User::factory()->withRole(Role::SalesManager)->create())->test(Objections::class)
            ->assertViewHas('losses', fn ($losses) => $losses->count() === 2);
    }
}
