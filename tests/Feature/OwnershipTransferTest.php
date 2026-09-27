<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Livewire\Leads\Index as LeadsIndex;
use App\Livewire\Leads\Show as LeadsShow;
use App\Livewire\Registry\Show as RegistryShow;
use App\Livewire\Team\Index;
use App\Models\Activity;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadTransfer;
use App\Models\PropertyEngagement;
use App\Models\User;
use App\Notifications\SmartAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class OwnershipTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_transfers_a_lead_and_every_detail_is_recorded(): void
    {
        Notification::fake();
        $john = User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);
        $mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambua']);
        $manager = User::factory()->withRole(Role::SalesManager)->create(['name' => 'David Otieno']);
        $engagement = PropertyEngagement::factory()->forRep($john)->create(['name' => 'ABC Hotel']);
        $lead = Lead::factory()->for($john)->create(['business_name' => 'ABC Hotel', 'property_engagement_id' => $engagement->id]);
        $activity = Activity::factory()->for($lead)->for($john)->create(['type' => ActivityType::Call]);
        $open = FollowUp::factory()->for($john)->for($lead)->create(['due_at' => now()->addDay()]);
        $done = FollowUp::factory()->for($john)->for($lead)->create(['due_at' => now()->subDay(), 'completed_at' => now()]);

        Livewire::actingAs($manager)->test(LeadsShow::class, ['lead' => $lead])
            ->call('openTransfer')
            ->set('transfer.to', (string) $mary->id)
            ->set('transfer.reason', 'territory')
            ->set('transfer.notes', 'John moves to Western region.')
            ->call('saveTransfer')
            ->assertHasNoErrors();

        $transfer = LeadTransfer::sole();
        $this->assertSame([$john->id, $mary->id, $manager->id, 'territory', 'John moves to Western region.'], [
            $transfer->from_user_id, $transfer->to_user_id, $transfer->transferred_by, $transfer->reason, $transfer->notes,
        ]);
        $this->assertSame('Territory reassignment', $transfer->reasonLabel());
        $this->assertNotNull($transfer->created_at);

        $this->assertSame($mary->id, $lead->fresh()->user_id);
        $this->assertSame($mary->id, $open->fresh()->user_id, 'Open schedule moves to the new owner.');
        $this->assertSame($john->id, $done->fresh()->user_id, 'Completed items stay with whoever did them.');
        $this->assertSame($john->id, $activity->fresh()->user_id, 'History stays attributed to the original salesperson.');

        // The registry follows because John was its representative, with the same reason.
        $engagement->refresh();
        $this->assertSame($mary->id, $engagement->sales_rep_id);
        $this->assertDatabaseHas('property_engagement_reps', ['property_engagement_id' => $engagement->id, 'user_id' => $mary->id, 'reason' => 'territory', 'assigned_by' => $manager->id]);
        $this->assertNotNull($engagement->reps()->where('user_id', $john->id)->value('ended_on'));

        Notification::assertSentTo($mary, SmartAlert::class, fn (SmartAlert $alert) => $alert->type === 'ownership_transferred');

        Livewire::actingAs($mary)->test(LeadsShow::class, ['lead' => $lead->fresh()])
            ->assertSee('Ownership')
            ->assertSee('Territory reassignment · by David Otieno');
    }

    public function test_salespeople_cannot_transfer_leads(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $mary = User::factory()->withRole(Role::Salesperson)->create();
        $lead = Lead::factory()->for($john)->create();

        Livewire::actingAs($john)->test(LeadsShow::class, ['lead' => $lead])
            ->assertDontSee('Transfer ownership')
            ->call('openTransfer')
            ->assertForbidden();

        Livewire::actingAs($john)->test(LeadsShow::class, ['lead' => $lead])
            ->set('transfer', ['to' => (string) $mary->id, 'reason' => 'territory', 'notes' => '', 'with_registry' => true])
            ->call('saveTransfer')
            ->assertForbidden();

        $this->assertSame($john->id, $lead->fresh()->user_id);
    }

    public function test_a_transfer_needs_a_reason_and_notes_for_other(): void
    {
        $manager = User::factory()->withRole(Role::SalesAdmin)->create();
        $lead = Lead::factory()->for(User::factory()->withRole(Role::Salesperson))->create();
        $mary = User::factory()->withRole(Role::Salesperson)->create();

        Livewire::actingAs($manager)->test(LeadsShow::class, ['lead' => $lead])
            ->call('openTransfer')
            ->set('transfer.to', (string) $mary->id)
            ->call('saveTransfer')
            ->assertHasErrors('transfer.reason')
            ->set('transfer.reason', 'other')
            ->call('saveTransfer')
            ->assertHasErrors('transfer.notes');

        $this->assertSame(0, LeadTransfer::count());
    }

    public function test_bulk_transfer_moves_only_open_leads_of_a_departing_salesperson(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create(['is_active' => false]);
        $mary = User::factory()->withRole(Role::Salesperson)->create();
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        Lead::factory()->for($john)->count(3)->create(['status' => LeadStatus::Contacted]);
        $lost = Lead::factory()->for($john)->create(['status' => LeadStatus::Lost]);

        Livewire::actingAs($manager)->test(LeadsIndex::class)
            ->set('owner', (string) $john->id)
            ->assertSee('Transfer 3 open leads')
            ->call('openBulkTransfer')
            ->set('bulk.to', (string) $mary->id)
            ->set('bulk.reason', 'left')
            ->call('saveBulkTransfer')
            ->assertHasNoErrors();

        $this->assertSame(3, Lead::query()->where('user_id', $mary->id)->count());
        $this->assertSame($john->id, $lost->fresh()->user_id);
        $this->assertSame(3, LeadTransfer::query()->where('reason', 'left')->count());
    }

    public function test_registry_assign_and_transfer_record_the_reason(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create(['name' => 'David Otieno']);
        $john = User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);
        $mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambua']);
        $engagement = PropertyEngagement::factory()->create(['sales_rep_id' => null]);

        Livewire::actingAs($manager)->test(RegistryShow::class, ['engagement' => $engagement->id])
            ->assertSee('Assign property')
            ->call('openReassign')
            ->set('newRep', (string) $john->id)
            ->call('saveReassign')
            ->assertHasNoErrors();

        $this->assertSame($john->id, $engagement->fresh()->sales_rep_id);
        $this->assertDatabaseHas('property_engagement_events', ['property_engagement_id' => $engagement->id, 'type' => 'rep_changed', 'summary' => 'Property assigned']);

        Livewire::actingAs($manager)->test(RegistryShow::class, ['engagement' => $engagement->id])
            ->assertSee('Transfer ownership')
            ->call('openReassign')
            ->set('newRep', (string) $mary->id)
            ->call('saveReassign')
            ->assertHasErrors('repReason')
            ->set('repReason', 'territory')
            ->set('repNotes', 'Coast team takes Mombasa properties.')
            ->call('saveReassign')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('property_engagement_events', [
            'property_engagement_id' => $engagement->id, 'type' => 'rep_changed', 'from_value' => 'John Doe', 'to_value' => 'Mary Wambua',
            'recorded_by' => $manager->id, 'summary' => 'Transferred · Territory reassignment', 'notes' => 'Coast team takes Mombasa properties.',
        ]);

        Livewire::actingAs($manager)->test(RegistryShow::class, ['engagement' => $engagement->id])
            ->assertSee('Territory reassignment · by David Otieno');
    }

    public function test_suspending_or_firing_a_salesperson_prompts_a_handover(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $john = User::factory()->withRole(Role::Salesperson)->create();
        Lead::factory()->for($john)->count(2)->create(['status' => LeadStatus::Contacted]);

        Livewire::actingAs($admin)->test(Index::class)
            ->call('openAccountAction', $john->id, 'terminate')
            ->assertSee('Hand over their work')
            ->assertSee('2 open leads');
    }
}
