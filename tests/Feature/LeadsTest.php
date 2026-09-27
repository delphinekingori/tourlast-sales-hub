<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Livewire\Activities\Index as ActivitiesIndex;
use App\Livewire\Leads\Index;
use App\Livewire\Leads\Show;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LeadsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_salesperson_can_add_a_lead(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();

        Livewire::actingAs($john)->test(Index::class)
            ->call('openCreate')
            ->set('form.business_name', 'ABC Hotel')
            ->set('form.property_type', 'hotel')
            ->set('form.contact_email', 'GM@ABC.co.ke')
            ->call('create')
            ->assertHasNoErrors();

        $lead = Lead::sole();
        $this->assertTrue($lead->user->is($john));
        $this->assertSame(LeadStatus::New, $lead->status);
        $this->assertSame('gm@abc.co.ke', $lead->contact_email);
    }

    public function test_salespeople_see_only_their_own_leads(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $mary = User::factory()->withRole(Role::Salesperson)->create();
        Lead::factory()->for($john)->create(['business_name' => 'Johns Lead']);
        $marysLead = Lead::factory()->for($mary)->create(['business_name' => 'Marys Lead']);

        Livewire::actingAs($john)->test(Index::class)->assertSee('Johns Lead')->assertDontSee('Marys Lead');
        $this->actingAs($john)->get(route('leads.show', $marysLead))->assertForbidden();
    }

    public function test_logging_a_meeting_moves_the_lead_forward_and_schedules_the_follow_up(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $lead = Lead::factory()->for($john)->create(['status' => LeadStatus::New]);

        Livewire::actingAs($john)->test(Show::class, ['lead' => $lead])
            ->call('openActivity')
            ->set('activity.type', 'meeting')
            ->set('activity.notes', 'Met the GM.')
            ->set('activity.next_action', 'Send the link')
            ->set('activity.follow_up_at', now()->addDays(2)->toDateString())
            ->call('saveActivity')
            ->assertHasNoErrors();

        $lead->refresh();
        $this->assertSame(LeadStatus::Meeting, $lead->status);
        $this->assertNotNull($lead->last_contacted_at);
        $this->assertSame('Send the link', FollowUp::sole()->task);
    }

    public function test_marking_a_lead_lost_needs_a_reason_and_onboarded_cannot_be_set_by_hand(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $lead = Lead::factory()->for($john)->create();

        Livewire::actingAs($john)->test(Show::class, ['lead' => $lead])->call('setStatus', 'lost')->assertStatus(422);
        Livewire::actingAs($john)->test(Show::class, ['lead' => $lead])->call('openLost')->call('markLost')->assertHasErrors('lost.objection');
        Livewire::actingAs($john)->test(Show::class, ['lead' => $lead])->call('openLost')->set('lost.objection', 'other_ota')->call('markLost')->assertHasErrors('lost.competitor');
        Livewire::actingAs($john)->test(Show::class, ['lead' => $lead])->call('openLost')->set('lost.objection', 'other_ota')->set('lost.competitor', 'Booking.com')->call('markLost')->assertHasNoErrors();
        $this->assertSame(LeadStatus::Lost, $lead->fresh()->status);

        Livewire::actingAs($john)->test(Show::class, ['lead' => $lead])->call('setStatus', 'onboarded')->assertStatus(422);
    }

    public function test_managers_can_view_any_lead_but_not_change_it(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $lead = Lead::factory()->create(['business_name' => 'Coral Bay Villas']);

        $this->actingAs($manager)->get(route('leads.show', $lead))->assertOk()->assertSee('Coral Bay Villas');
        Livewire::actingAs($manager)->test(Show::class, ['lead' => $lead])->call('openActivity')->assertForbidden();
    }

    public function test_follow_ups_can_be_completed_from_the_activities_page(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $followUp = FollowUp::factory()->for($john)->for(Lead::factory()->for($john))->create(['due_at' => now()->subDay()]);

        Livewire::actingAs($john)->test(ActivitiesIndex::class)->assertSee($followUp->task)->call('complete', $followUp->id);

        $this->assertNotNull($followUp->fresh()->completed_at);
    }
}
