<?php

namespace Tests\Feature;

use App\Enums\EngagementStatus;
use App\Enums\LeadStatus;
use App\Enums\Objection;
use App\Enums\Role;
use App\Livewire\Insights\Objections;
use App\Livewire\Leads\Show as LeadsShow;
use App\Livewire\Registry\Form as RegistryForm;
use App\Livewire\Registry\Show as RegistryShow;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\PropertyEngagement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LostAndReengagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_marking_a_lead_lost_records_the_objection_and_schedules_re_engagement(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $lead = Lead::factory()->for($john)->create(['business_name' => 'ABC Hotel', 'status' => LeadStatus::Meeting]);

        Livewire::actingAs($john)->test(LeadsShow::class, ['lead' => $lead])
            ->call('openLost')
            ->set('lost.objection', 'other_ota')
            ->set('lost.competitor', 'Booking.com')
            ->set('lost.notes', 'Management renewed contract for another year.')
            ->set('lost.reengage_on', '2027-03-01')
            ->call('markLost')
            ->assertHasNoErrors();

        $lead->refresh();
        $this->assertSame(LeadStatus::Lost, $lead->status);
        $this->assertSame(Objection::OtherOta, $lead->objection);
        $this->assertSame('Booking.com', $lead->competitor);
        $this->assertSame('Already using another OTA (Booking.com)', $lead->lost_reason);
        $this->assertNotNull($lead->lost_at);
        $this->assertSame('2027-03-01', $lead->reengage_on->toDateString());

        $followUp = FollowUp::query()->where('lead_id', $lead->id)->sole();
        $this->assertSame('Re-engage after loss', $followUp->task);
        $this->assertSame('2027-03-01', $followUp->due_at->toDateString());
        $this->assertSame($john->id, $followUp->user_id);

        Livewire::actingAs($john)->test(LeadsShow::class, ['lead' => $lead])
            ->assertSee('Why it was lost')
            ->assertSee('Management renewed contract for another year.')
            ->assertSee('1 Mar 2027');
    }

    public function test_re_engage_date_must_be_in_the_future(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $lead = Lead::factory()->for($john)->create();

        Livewire::actingAs($john)->test(LeadsShow::class, ['lead' => $lead])
            ->call('openLost')
            ->set('lost.objection', 'timing')
            ->set('lost.reengage_on', now()->subDay()->toDateString())
            ->call('markLost')
            ->assertHasErrors('lost.reengage_on');
    }

    public function test_reopening_a_lost_lead_clears_the_pending_re_engagement(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $lead = Lead::factory()->for($john)->create();

        Livewire::actingAs($john)->test(LeadsShow::class, ['lead' => $lead])
            ->call('openLost')->set('lost.objection', 'timing')->set('lost.reengage_on', now()->addMonths(3)->toDateString())->call('markLost')
            ->call('setStatus', 'contacted');

        $this->assertSame(LeadStatus::Contacted, $lead->fresh()->status);
        $this->assertSame(0, FollowUp::query()->whereNull('completed_at')->count());
        $this->assertSame(Objection::Timing, $lead->fresh()->objection, 'The objection stays on record.');
    }

    public function test_registry_lost_requires_an_objection_when_logging_engagement(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $engagement = PropertyEngagement::factory()->create();

        $show = Livewire::actingAs($manager)->test(RegistryShow::class, ['engagement' => $engagement->id])
            ->call('openLog')
            ->set('log.type', 'meeting')
            ->set('log.summary', 'Final meeting')
            ->set('log.status', 'lost')
            ->call('saveLog')
            ->assertHasErrors('log.objection');

        $show->set('log.objection', 'commission')
            ->set('log.outcome_notes', 'Wanted 10% instead of 15%.')
            ->set('log.reengage_on', now()->addMonths(4)->toDateString())
            ->call('saveLog')
            ->assertHasNoErrors();

        $engagement->refresh();
        $this->assertSame(EngagementStatus::Lost, $engagement->status);
        $this->assertSame(Objection::Commission, $engagement->objection);
        $this->assertSame('Wanted 10% instead of 15%.', $engagement->outcome_notes);
        $this->assertNotNull($engagement->closed_at);
        $this->assertDatabaseHas('property_engagement_events', ['property_engagement_id' => $engagement->id, 'type' => 'status_changed', 'to_value' => 'lost']);
        $this->assertStringContainsString('Objection: Commission', $engagement->events()->where('type', 'status_changed')->value('summary'));
    }

    public function test_registry_form_requires_an_objection_for_rejected(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $engagement = PropertyEngagement::factory()->create(['property_type' => 'hotel']);

        Livewire::actingAs($manager)->test(RegistryForm::class, ['engagement' => $engagement])
            ->set('status', 'rejected')
            ->call('save')
            ->assertHasErrors('outcome.objection')
            ->set('outcome.objection', 'has_pms')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(Objection::HasPms, $engagement->fresh()->objection);
    }

    public function test_due_re_engagements_come_back_as_sales_actions(): void
    {
        $mary = User::factory()->withRole(Role::Salesperson)->create();
        $engagement = PropertyEngagement::factory()->forRep($mary)->create([
            'status' => EngagementStatus::Lost, 'objection' => Objection::Timing, 'reengage_on' => now()->toDateString(), 'closed_at' => now()->subMonths(5),
        ]);
        $lead = Lead::factory()->for($mary)->create(['property_engagement_id' => $engagement->id, 'status' => LeadStatus::Lost]);
        $notDue = PropertyEngagement::factory()->create(['status' => EngagementStatus::Lost, 'reengage_on' => now()->addMonth()->toDateString()]);

        $this->artisan('hub:process-reengagements')->assertSuccessful();

        $engagement->refresh();
        $this->assertSame(EngagementStatus::ReEngage, $engagement->status);
        $this->assertNull($engagement->reengage_on);
        $this->assertSame(Objection::Timing, $engagement->objection, 'The original objection stays on record.');
        $this->assertTrue($lead->followUps()->where('task', 'Re-engage after loss')->whereNull('completed_at')->exists());
        $this->assertSame(1, $mary->notifications()->count());
        $this->assertSame(EngagementStatus::Lost, $notDue->fresh()->status);
    }

    public function test_insights_show_why_properties_say_no(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);
        Lead::factory()->for($john)->count(2)->create(['status' => LeadStatus::Lost, 'objection' => Objection::Commission, 'lost_at' => now()]);
        Lead::factory()->for($john)->create(['status' => LeadStatus::Lost, 'objection' => Objection::OtherOta, 'competitor' => 'Booking.com', 'lost_at' => now(), 'reengage_on' => now()->addMonth()->toDateString()]);
        Lead::factory()->for($john)->create(['status' => LeadStatus::Lost, 'lost_reason' => 'Old free-text reason', 'lost_at' => now()]);

        Livewire::actingAs(User::factory()->withRole(Role::SalesManager)->create())->test(Objections::class)
            ->assertSee('Why hotels refuse Tourlast')
            ->assertSeeInOrder(['Commission', 'Already using another OTA'])
            ->assertSee('Booking.com')
            ->assertViewHas('unexplained', 1)
            ->assertViewHas('upcoming', fn ($upcoming) => $upcoming->count() === 1);

        $this->actingAs($john)->get(route('insights.objections'))->assertForbidden();
    }
}
