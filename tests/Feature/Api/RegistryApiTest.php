<?php

namespace Tests\Feature\Api;

use App\Enums\ActivityType;
use App\Enums\EngagementStage;
use App\Enums\EngagementStatus;
use App\Enums\OnboardingStatus;
use App\Enums\Role;
use App\Models\Activity;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Onboarding;
use App\Models\PropertyEngagement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class RegistryApiTest extends TestCase
{
    use MakesApiRequests, RefreshDatabase;

    public function test_everyone_can_search_filter_and_sort_the_registry(): void
    {
        $mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambua']);
        PropertyEngagement::factory()->forRep($mary)->stage(EngagementStage::ProposalSent)->create(['name' => 'ABC Hotel', 'city' => 'Nairobi', 'region' => 'Nairobi', 'property_type' => 'hotel']);
        PropertyEngagement::factory()->stage(EngagementStage::Contacted, EngagementStatus::Stalled)->create(['name' => 'XYZ Apartments', 'city' => 'Mombasa', 'region' => 'Mombasa', 'property_type' => 'apartment']);

        foreach ([Role::Salesperson, Role::Hr, Role::Accounts] as $role) {
            $this->api(User::factory()->withRole($role)->create(), ['registry:read'])->getJson('/api/v1/registry')
                ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('summary.total', 2)->assertJsonPath('summary.stalled', 1);
        }

        $salesperson = User::factory()->withRole(Role::Salesperson)->create();
        $this->api($salesperson, ['registry:read'])->getJson('/api/v1/registry?q=Mombasa')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'XYZ Apartments');
        $this->api($salesperson, ['registry:read'])->getJson('/api/v1/registry?q=Mary+Wambua')->assertJsonPath('data.0.name', 'ABC Hotel');
        $this->api($salesperson, ['registry:read'])->getJson('/api/v1/registry?status=stalled')->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'stalled');
        $this->api($salesperson, ['registry:read'])->getJson('/api/v1/registry?sort=name&dir=asc')->assertJsonPath('data.0.name', 'ABC Hotel')->assertJsonPath('data.1.name', 'XYZ Apartments');
        $this->api($salesperson, ['registry:read'])->getJson('/api/v1/registry?per_page=1')->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
    }

    public function test_the_profile_merges_registry_events_lead_activity_and_upcoming_meetings(): void
    {
        $mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambua']);
        $engagement = PropertyEngagement::factory()->forRep($mary)->create(['name' => 'PrideInn Paradise Beach Resort']);
        $engagement->events()->create(['type' => 'meeting', 'sales_rep_id' => $mary->id, 'summary' => 'Meeting completed', 'happened_at' => now()->subDays(5)]);
        $lead = Lead::factory()->for($mary)->create(['property_engagement_id' => $engagement->id]);
        Activity::factory()->for($lead)->for($mary)->create(['type' => ActivityType::Call, 'notes' => 'GM confirmed interest.', 'happened_at' => now()->subDay()]);
        FollowUp::factory()->for($mary)->for($lead)->create(['type' => ActivityType::Meeting, 'task' => 'Proposal discussion', 'due_at' => now()->addDays(2)->setTime(11, 30), 'has_time' => true]);

        $this->api(User::factory()->withRole(Role::Salesperson)->create(), ['registry:read'])->getJson('/api/v1/registry/'.$engagement->id)
            ->assertOk()
            ->assertJsonPath('data.name', 'PrideInn Paradise Beach Resort')
            ->assertJsonPath('data.timeline.0.kind', 'lead_activity')
            ->assertJsonPath('data.timeline.0.via_lead', true)
            ->assertJsonPath('data.timeline.0.notes', 'GM confirmed interest.')
            ->assertJsonPath('data.timeline.1.summary', 'Meeting completed')
            ->assertJsonPath('data.upcoming.0.title', 'Proposal discussion')
            ->assertJsonPath('data.can.update', false)
            ->assertJsonCount(1, 'data.leads');
    }

    public function test_managers_create_records_with_duplicate_protection(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $rep = User::factory()->withRole(Role::Salesperson)->create();
        $existing = PropertyEngagement::factory()->create(['name' => 'PrideInn Paradise Beach Resort', 'city' => 'Mombasa']);

        $payload = $this->payload($rep, ['name' => 'PrideInn Paradise', 'city' => 'Mombasa', 'region' => 'Mombasa']);

        $this->api($manager, ['registry:write'])->postJson('/api/v1/registry', $payload)
            ->assertStatus(409)
            ->assertJsonPath('matches.0.kind', 'registry')
            ->assertJsonPath('matches.0.engagement_id', $existing->id);

        $this->assertSame(1, PropertyEngagement::count());

        $this->api($manager, ['registry:write'])->postJson('/api/v1/registry', $payload + ['confirm_different' => true])
            ->assertCreated()
            ->assertJsonPath('data.name', 'PrideInn Paradise')
            ->assertJsonPath('data.sales_rep.id', $rep->id)
            ->assertJsonPath('data.contacts.0.is_primary', true);

        $created = PropertyEngagement::query()->where('name', 'PrideInn Paradise')->sole();
        $this->assertSame([$existing->id], $created->events()->first()->changes['duplicate_override']);
    }

    public function test_lost_or_rejected_needs_an_objection(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $rep = User::factory()->withRole(Role::Salesperson)->create();
        $payload = $this->payload($rep, ['name' => 'Tulia Retreat', 'status' => 'lost']);

        $this->api($manager, ['registry:write'])->postJson('/api/v1/registry', $payload)->assertUnprocessable()->assertJsonValidationErrors('objection');
        $this->api($manager, ['registry:write'])->postJson('/api/v1/registry', $payload + ['objection' => 'other_ota'])->assertUnprocessable()->assertJsonValidationErrors('competitor');
        $this->api($manager, ['registry:write'])->postJson('/api/v1/registry', $payload + ['objection' => 'other_ota', 'competitor' => 'Booking.com'])
            ->assertCreated()->assertJsonPath('data.outcome.objection', 'other_ota')->assertJsonPath('data.outcome.competitor', 'Booking.com');
    }

    public function test_editing_audits_changes_and_a_rep_change_needs_a_reason(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $john = User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);
        $mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambua']);
        $engagement = PropertyEngagement::factory()->forRep($john)->create(['city' => 'Nairobi', 'property_type' => 'hotel', 'star_rating' => '4']);

        $this->api($manager, ['registry:write'])->patchJson('/api/v1/registry/'.$engagement->id, ['sales_rep_id' => $mary->id])
            ->assertUnprocessable()->assertJsonValidationErrors('rep_reason');

        $this->api($manager, ['registry:write'])->patchJson('/api/v1/registry/'.$engagement->id, ['city' => 'Nakuru', 'sales_rep_id' => $mary->id, 'rep_reason' => 'territory', 'stage' => 'proposal_sent'])
            ->assertOk()->assertJsonPath('data.location.city', 'Nakuru')->assertJsonPath('data.sales_rep.id', $mary->id)->assertJsonPath('data.stage', 'proposal_sent');

        $this->assertDatabaseHas('property_engagement_events', ['property_engagement_id' => $engagement->id, 'type' => 'edited']);
        $this->assertDatabaseHas('property_engagement_events', ['property_engagement_id' => $engagement->id, 'type' => 'rep_changed', 'from_value' => 'John Doe', 'to_value' => 'Mary Wambua']);
        $this->assertDatabaseHas('property_engagement_events', ['property_engagement_id' => $engagement->id, 'type' => 'stage_changed', 'to_value' => 'proposal_sent']);
        $this->assertDatabaseHas('property_engagement_reps', ['property_engagement_id' => $engagement->id, 'user_id' => $mary->id, 'reason' => 'territory']);
    }

    public function test_logging_engagement_moves_stage_and_can_hand_over(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $mary = User::factory()->withRole(Role::Salesperson)->create();
        $engagement = PropertyEngagement::factory()->forRep($john)->create();

        $this->api($manager, ['registry:write'])->postJson('/api/v1/registry/'.$engagement->id.'/engagements', [
            'type' => 'meeting', 'rep_id' => $mary->id, 'happened_on' => now()->toDateString(), 'summary' => 'Meeting completed',
            'notes' => 'GM requested a commercial proposal.', 'stage' => 'meeting_completed',
        ])->assertOk()->assertJsonPath('data.stage', 'meeting_completed')->assertJsonPath('data.sales_rep.id', $mary->id);

        $this->api($manager, ['registry:write'])->postJson('/api/v1/registry/'.$engagement->id.'/engagements', [
            'type' => 'call', 'happened_on' => now()->toDateString(), 'summary' => 'Final call', 'status' => 'lost',
        ])->assertUnprocessable()->assertJsonValidationErrors('objection');

        $this->api($manager, ['registry:write'])->postJson('/api/v1/registry/'.$engagement->id.'/engagements', [
            'type' => 'call', 'happened_on' => now()->addDay()->toDateString(), 'summary' => 'Future',
        ])->assertUnprocessable()->assertJsonValidationErrors('happened_on');
    }

    public function test_assigning_and_transferring_ownership(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $john = User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);
        $mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambua']);
        $engagement = PropertyEngagement::factory()->create(['sales_rep_id' => null]);

        $this->api($manager, ['registry:write'])->postJson('/api/v1/registry/'.$engagement->id.'/assign', ['rep_id' => $john->id])
            ->assertOk()->assertJsonPath('data.sales_rep.id', $john->id);

        $this->api($manager, ['registry:write'])->postJson('/api/v1/registry/'.$engagement->id.'/assign', ['rep_id' => $mary->id])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->api($manager, ['registry:write'])->postJson('/api/v1/registry/'.$engagement->id.'/assign', ['rep_id' => $john->id, 'reason' => 'territory'])
            ->assertUnprocessable()->assertJsonValidationErrors('rep_id');
        $this->api($manager, ['registry:write'])->postJson('/api/v1/registry/'.$engagement->id.'/assign', ['rep_id' => $mary->id, 'reason' => 'other'])
            ->assertUnprocessable()->assertJsonValidationErrors('notes');

        $this->api($manager, ['registry:write'])->postJson('/api/v1/registry/'.$engagement->id.'/assign', ['rep_id' => $mary->id, 'reason' => 'territory', 'notes' => 'Coast team takes Mombasa.'])
            ->assertOk()->assertJsonPath('data.sales_rep.id', $mary->id)->assertJsonPath('data.reps.0.reason', 'territory');

        $this->assertDatabaseHas('property_engagement_events', ['property_engagement_id' => $engagement->id, 'type' => 'rep_changed', 'from_value' => 'John Doe', 'to_value' => 'Mary Wambua', 'summary' => 'Transferred · Territory reassignment']);
    }

    public function test_contacts_are_added_made_primary_and_removed_with_history(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $engagement = PropertyEngagement::factory()->create();
        $original = $engagement->primaryContact;

        $contactId = $this->api($manager, ['registry:write'])->postJson('/api/v1/registry/'.$engagement->id.'/contacts', [
            'name' => 'Mary Doe', 'title' => 'Sales Manager', 'phone' => '+254700111222', 'is_decision_maker' => true,
        ])->assertCreated()->assertJsonPath('data.is_decision_maker', true)->json('data.id');

        $this->api($manager, ['registry:write'])->deleteJson('/api/v1/registry/'.$engagement->id.'/contacts/'.$original->id)
            ->assertUnprocessable()->assertJsonValidationErrors('contact');

        $this->api($manager, ['registry:write'])->postJson('/api/v1/registry/'.$engagement->id.'/contacts/'.$contactId.'/primary')->assertOk()->assertJsonPath('data.is_primary', true);
        $this->api($manager, ['registry:write'])->deleteJson('/api/v1/registry/'.$engagement->id.'/contacts/'.$original->id)->assertOk();

        $this->assertModelMissing($original);
        $this->assertDatabaseHas('property_engagement_events', ['property_engagement_id' => $engagement->id, 'type' => 'contact_added']);
        $this->assertDatabaseHas('property_engagement_events', ['property_engagement_id' => $engagement->id, 'type' => 'contact_removed']);
    }

    public function test_linking_a_signup_makes_the_stage_follow_tourlast(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $engagement = PropertyEngagement::factory()->forRep($john)->stage(EngagementStage::OnboardingStarted)->create();
        $onboarding = Onboarding::factory()->forSalesperson($john)->create(['status' => OnboardingStatus::UnderReview]);
        $lead = Lead::factory()->for($john)->create();

        $this->api($manager, ['registry:write'])->postJson('/api/v1/registry/'.$engagement->id.'/links', ['onboarding_id' => $onboarding->id])
            ->assertOk()->assertJsonPath('data.stage', 'verification')->assertJsonCount(1, 'data.onboardings');

        $this->api($manager, ['registry:write'])->postJson('/api/v1/registry/'.$engagement->id.'/links', ['onboarding_id' => $onboarding->id])
            ->assertUnprocessable()->assertJsonValidationErrors('onboarding_id');

        $this->api($manager, ['registry:write'])->postJson('/api/v1/registry/'.$engagement->id.'/links', ['lead_id' => $lead->id])
            ->assertOk()->assertJsonCount(1, 'data.leads');

        $this->assertSame($engagement->id, $lead->fresh()->property_engagement_id);
    }

    public function test_archived_records_are_hidden_from_salespeople_and_can_be_restored(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $salesperson = User::factory()->withRole(Role::Salesperson)->create();
        $engagement = PropertyEngagement::factory()->create(['name' => 'Archived Lodge']);

        $this->api($admin, ['registry:write'])->postJson('/api/v1/registry/'.$engagement->id.'/archive')->assertOk()->assertJsonPath('data.archived', true);
        $this->assertSoftDeleted($engagement);

        $this->api($salesperson, ['registry:read'])->getJson('/api/v1/registry/'.$engagement->id)->assertForbidden();
        $this->api($salesperson, ['registry:read'])->getJson('/api/v1/registry?archived=1')->assertJsonCount(0, 'data');
        $this->api($admin, ['registry:read'])->getJson('/api/v1/registry?archived=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Archived Lodge');

        $this->api($admin, ['registry:write'])->patchJson('/api/v1/registry/'.$engagement->id, ['city' => 'Nakuru'])->assertForbidden();
        $this->api($admin, ['registry:write'])->postJson('/api/v1/registry/'.$engagement->id.'/restore')->assertOk()->assertJsonPath('data.archived', false);
        $this->assertNotSoftDeleted($engagement);
    }

    public function test_salespeople_hr_and_accounts_cannot_change_the_registry(): void
    {
        $engagement = PropertyEngagement::factory()->create();
        $rep = User::factory()->withRole(Role::Salesperson)->create();

        foreach ([Role::Salesperson, Role::Hr, Role::Accounts] as $role) {
            $user = User::factory()->withRole($role)->create();
            $this->api($user, ['registry:write'])->postJson('/api/v1/registry', $this->payload($rep, ['name' => 'Kijani Gardens']))->assertForbidden();
            $this->api($user, ['registry:write'])->patchJson('/api/v1/registry/'.$engagement->id, ['city' => 'Nakuru'])->assertForbidden();
            $this->api($user, ['registry:write'])->postJson('/api/v1/registry/'.$engagement->id.'/engagements', ['type' => 'call', 'happened_on' => now()->toDateString(), 'summary' => 'x'])->assertForbidden();
            $this->api($user, ['registry:write'])->postJson('/api/v1/registry/'.$engagement->id.'/assign', ['rep_id' => $rep->id, 'reason' => 'territory'])->assertForbidden();
            $this->api($user, ['registry:write'])->postJson('/api/v1/registry/'.$engagement->id.'/contacts', ['name' => 'X', 'title' => 'Owner', 'phone' => '+254700000000'])->assertForbidden();
            $this->api($user, ['registry:write'])->postJson('/api/v1/registry/'.$engagement->id.'/archive')->assertForbidden();
        }

        $this->assertSame(1, PropertyEngagement::count());
        $this->assertNotSoftDeleted($engagement);
    }

    public function test_tokens_need_the_registry_scopes(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $engagement = PropertyEngagement::factory()->create();

        $this->api($manager, ['leads:read'])->getJson('/api/v1/registry')->assertForbidden()->assertJsonPath('required_scopes', ['registry:read']);
        $this->api($manager, ['registry:read'])->postJson('/api/v1/registry/'.$engagement->id.'/archive')->assertForbidden()->assertJsonPath('required_scopes', ['registry:write']);
        $this->api($manager, ['registry:read'])->getJson('/api/v1/registry/999999')->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(User $rep, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Kifaru Lodge',
            'property_type' => 'lodge',
            'country' => 'Kenya',
            'region' => 'Narok',
            'city' => 'Maasai Mara',
            'contact_name' => 'Jane Owner',
            'contact_title' => 'Owner',
            'contact_phone' => '+254 700 '.random_int(100000, 999999),
            'sales_rep_id' => $rep->id,
            'first_engaged_on' => now()->subWeek()->toDateString(),
            'stage' => 'contacted',
            'status' => 'active',
        ], $overrides);
    }
}
