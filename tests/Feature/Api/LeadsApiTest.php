<?php

namespace Tests\Feature\Api;

use App\Enums\ActivityType;
use App\Enums\EngagementStage;
use App\Enums\LeadStatus;
use App\Enums\Objection;
use App\Enums\Role;
use App\Models\Activity;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadTransfer;
use App\Models\PropertyEngagement;
use App\Models\User;
use App\Notifications\SmartAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class LeadsApiTest extends TestCase
{
    use MakesApiRequests, RefreshDatabase;

    public function test_salespeople_list_only_their_own_leads_and_managers_see_all(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $mary = User::factory()->withRole(Role::Salesperson)->create();
        Lead::factory()->for($john)->create(['business_name' => 'Johns Hotel']);
        Lead::factory()->for($mary)->create(['business_name' => 'Marys Villa']);

        $this->api($john, ['leads:read'])->getJson('/api/v1/leads')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.business_name', 'Johns Hotel');

        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $this->api($manager, ['leads:read'])->getJson('/api/v1/leads?owner='.$mary->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.business_name', 'Marys Villa');
        $this->api($manager, ['leads:read'])->getJson('/api/v1/leads')->assertOk()->assertJsonCount(2, 'data')->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'total']]);
    }

    public function test_listing_filters_by_status_and_search(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        Lead::factory()->for($john)->create(['business_name' => 'ABC Hotel', 'status' => LeadStatus::Contacted]);
        Lead::factory()->for($john)->create(['business_name' => 'Lost Lodge', 'status' => LeadStatus::Lost]);

        $this->api($john, ['leads:read'])->getJson('/api/v1/leads')->assertJsonCount(1, 'data');
        $this->api($john, ['leads:read'])->getJson('/api/v1/leads?status=all')->assertJsonCount(2, 'data');
        $this->api($john, ['leads:read'])->getJson('/api/v1/leads?status=lost')->assertJsonPath('data.0.business_name', 'Lost Lodge');
        $this->api($john, ['leads:read'])->getJson('/api/v1/leads?status=all&q=ABC')->assertJsonCount(1, 'data');
    }

    public function test_missing_scope_is_rejected_with_the_scope_named(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();

        $this->api($john, ['leads:read'])->postJson('/api/v1/leads', ['business_name' => 'X', 'property_type' => 'hotel'])
            ->assertForbidden()->assertJsonPath('required_scopes', ['leads:write']);
        $this->api($john, ['profile'])->getJson('/api/v1/leads')->assertForbidden()->assertJsonPath('required_scopes', ['leads:read']);
    }

    public function test_a_salesperson_creates_a_lead(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();

        $this->api($john, ['leads:write'])->postJson('/api/v1/leads', [
            'business_name' => 'Twiga Springs Lodge', 'property_type' => 'lodge', 'location' => 'Naivasha',
            'contact_name' => 'Grace Wanjiru', 'contact_email' => 'GM@Twiga.co.ke', 'kra_pin' => 'p051234567x',
        ])->assertCreated()
            ->assertJsonPath('data.business_name', 'Twiga Springs Lodge')
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.contact_email', 'gm@twiga.co.ke')
            ->assertJsonPath('data.kra_pin', 'P051234567X')
            ->assertJsonPath('data.owner.id', $john->id);
    }

    public function test_non_sellers_cannot_create_leads(): void
    {
        $hr = User::factory()->withRole(Role::Hr)->create();

        $this->api($hr, ['leads:write'])->postJson('/api/v1/leads', ['business_name' => 'X', 'property_type' => 'hotel'])->assertForbidden();
    }

    public function test_duplicates_block_creation_until_confirmed_or_linked(): void
    {
        Notification::fake();
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambua']);
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        Lead::factory()->for($mary)->create(['business_name' => 'Tembo Lodge', 'status' => LeadStatus::Contacted]);

        $this->api($john, ['leads:write'])->postJson('/api/v1/leads', ['business_name' => 'Tembo Lodge Naivasha', 'property_type' => 'lodge'])
            ->assertStatus(409)
            ->assertJsonPath('matches.0.kind', 'lead')
            ->assertJsonPath('matches.0.owner', 'Mary Wambua')
            ->assertJsonPath('matches.0.lead_id', null);
        $this->assertSame(1, Lead::count());

        $this->api($john, ['leads:write'])->postJson('/api/v1/leads', ['business_name' => 'Tembo Lodge Naivasha', 'property_type' => 'lodge', 'confirm_different' => true])
            ->assertCreated();
        $this->assertSame(2, Lead::count());
        Notification::assertSentTo($manager, SmartAlert::class, fn (SmartAlert $alert) => $alert->type === 'duplicate_property' && $alert->title === 'Possible duplicate lead');
    }

    public function test_continuing_an_existing_registry_engagement(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $mary = User::factory()->withRole(Role::Salesperson)->create();
        $engagement = PropertyEngagement::factory()->forRep($mary)->stage(EngagementStage::ProposalSent)->create(['name' => 'PrideInn Paradise Beach Resort']);

        $this->api($john, ['leads:write'])->postJson('/api/v1/leads', ['business_name' => 'PrideInn Paradise', 'property_type' => 'resort'])
            ->assertStatus(409)->assertJsonPath('matches.0.engagement_id', $engagement->id);

        $this->api($john, ['leads:write'])->postJson('/api/v1/leads', ['business_name' => 'PrideInn Paradise', 'property_type' => 'resort', 'property_engagement_id' => $engagement->id])
            ->assertCreated()->assertJsonPath('data.property_engagement_id', $engagement->id);
        $this->assertSame($mary->id, $engagement->fresh()->sales_rep_id);
    }

    public function test_show_is_for_the_owner_and_managers(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $mary = User::factory()->withRole(Role::Salesperson)->create();
        $lead = Lead::factory()->for($john)->create();
        Activity::factory()->for($lead)->for($john)->create(['type' => ActivityType::Call]);
        FollowUp::factory()->for($john)->for($lead)->create(['due_at' => now()->addDay()]);

        $this->api($john, ['leads:read'])->getJson("/api/v1/leads/{$lead->id}")
            ->assertOk()->assertJsonCount(1, 'data.activities')->assertJsonCount(1, 'data.open_schedule')->assertJsonPath('data.transfers', []);
        $this->api($mary, ['leads:read'])->getJson("/api/v1/leads/{$lead->id}")->assertForbidden();
        $this->api(User::factory()->withRole(Role::SalesManager)->create(), ['leads:read'])->getJson("/api/v1/leads/{$lead->id}")->assertOk();
        $this->api($john, ['leads:read'])->getJson('/api/v1/leads/999999')->assertNotFound();
    }

    public function test_only_the_owner_updates_and_changes_status(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $lead = Lead::factory()->for($john)->create(['status' => LeadStatus::New]);
        $manager = User::factory()->withRole(Role::SalesManager)->create();

        $this->api($john, ['leads:write'])->patchJson("/api/v1/leads/{$lead->id}", ['contact_name' => 'Peter Otieno'])
            ->assertOk()->assertJsonPath('data.contact_name', 'Peter Otieno');
        $this->api($manager, ['leads:write'])->patchJson("/api/v1/leads/{$lead->id}", ['contact_name' => 'X'])->assertForbidden();

        $this->api($john, ['leads:write'])->postJson("/api/v1/leads/{$lead->id}/status", ['status' => 'meeting'])->assertOk()->assertJsonPath('data.status', 'meeting');
        $this->api($john, ['leads:write'])->postJson("/api/v1/leads/{$lead->id}/status", ['status' => 'onboarded'])->assertUnprocessable();
        $this->api($john, ['leads:write'])->postJson("/api/v1/leads/{$lead->id}/status", ['status' => 'lost'])->assertStatus(422);
    }

    public function test_marking_lost_requires_the_objection_and_competitor(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $lead = Lead::factory()->for($john)->create();

        $this->api($john, ['leads:write'])->postJson("/api/v1/leads/{$lead->id}/lost", [])->assertUnprocessable()->assertJsonValidationErrors('objection');
        $this->api($john, ['leads:write'])->postJson("/api/v1/leads/{$lead->id}/lost", ['objection' => 'other_ota'])->assertUnprocessable()->assertJsonValidationErrors('competitor');

        $this->api($john, ['leads:write'])->postJson("/api/v1/leads/{$lead->id}/lost", [
            'objection' => 'other_ota', 'competitor' => 'Booking.com', 'notes' => 'Renewed contract.', 'reengage_on' => now()->addMonths(3)->toDateString(),
        ])->assertOk()
            ->assertJsonPath('data.status', 'lost')
            ->assertJsonPath('data.lost.objection', 'other_ota')
            ->assertJsonPath('data.lost.competitor', 'Booking.com');

        $this->assertSame(Objection::OtherOta, $lead->fresh()->objection);
        $this->assertTrue($lead->followUps()->where('task', 'Re-engage after loss')->exists());

        // Re-opening clears the pending re-engagement.
        $this->api($john, ['leads:write'])->postJson("/api/v1/leads/{$lead->id}/status", ['status' => 'contacted'])->assertOk();
        $this->assertFalse($lead->followUps()->whereNull('completed_at')->exists());
    }

    public function test_managers_transfer_leads_but_salespeople_cannot(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $mary = User::factory()->withRole(Role::Salesperson)->create();
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $lead = Lead::factory()->for($john)->create();

        $this->api($john, ['leads:write'])->postJson("/api/v1/leads/{$lead->id}/transfer", ['to_user_id' => $mary->id, 'reason' => 'territory'])->assertForbidden();

        $this->api($manager, ['leads:write'])->postJson("/api/v1/leads/{$lead->id}/transfer", ['to_user_id' => $mary->id, 'reason' => 'other'])
            ->assertUnprocessable()->assertJsonValidationErrors('notes');
        $this->api($manager, ['leads:write'])->postJson("/api/v1/leads/{$lead->id}/transfer", ['to_user_id' => $john->id, 'reason' => 'territory'])
            ->assertUnprocessable()->assertJsonValidationErrors('to_user_id');

        $this->api($manager, ['leads:write'])->postJson("/api/v1/leads/{$lead->id}/transfer", ['to_user_id' => $mary->id, 'reason' => 'territory', 'notes' => 'Moved region'])
            ->assertOk()->assertJsonPath('data.owner.id', $mary->id)->assertJsonPath('data.transfers.0.reason', 'territory');

        $this->assertSame(1, LeadTransfer::count());
    }

    public function test_bulk_transfer_moves_only_open_leads(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $mary = User::factory()->withRole(Role::Salesperson)->create();
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        Lead::factory()->for($john)->count(2)->create(['status' => LeadStatus::Contacted]);
        $lost = Lead::factory()->for($john)->create(['status' => LeadStatus::Lost]);

        $this->api($john, ['leads:write'])->postJson('/api/v1/leads/transfer-bulk', ['from_user_id' => $john->id, 'to_user_id' => $mary->id, 'reason' => 'left'])->assertForbidden();

        $this->api($manager, ['leads:write'])->postJson('/api/v1/leads/transfer-bulk', ['from_user_id' => $john->id, 'to_user_id' => $mary->id, 'reason' => 'left'])
            ->assertOk()->assertJsonPath('transferred', 2);

        $this->assertSame(2, Lead::query()->where('user_id', $mary->id)->count());
        $this->assertSame($john->id, $lost->fresh()->user_id);
    }

    public function test_activities_are_listed_and_logged_by_the_owner(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $mary = User::factory()->withRole(Role::Salesperson)->create();
        $lead = Lead::factory()->for($john)->create(['status' => LeadStatus::New]);

        $this->api($john, ['leads:write'])->postJson("/api/v1/leads/{$lead->id}/activities", [
            'type' => 'meeting', 'happened_at' => now()->subHour()->toIso8601String(), 'notes' => 'Met the GM.',
            'next_action' => 'Send proposal', 'follow_up_at' => now()->addDays(2)->toDateString(), 'follow_up_time' => '10:00',
        ])->assertCreated()->assertJsonPath('data.type', 'meeting');

        $this->assertSame(LeadStatus::Meeting, $lead->fresh()->status);
        $followUp = FollowUp::sole();
        $this->assertTrue($followUp->has_time);
        $this->assertSame('10:00', $followUp->due_at->format('H:i'));

        $this->api($john, ['leads:read'])->getJson("/api/v1/leads/{$lead->id}/activities")->assertOk()->assertJsonCount(1, 'data');
        $this->api($mary, ['leads:write'])->postJson("/api/v1/leads/{$lead->id}/activities", ['type' => 'call', 'happened_at' => now()->toIso8601String()])->assertForbidden();
        $this->api($mary, ['leads:read'])->getJson("/api/v1/leads/{$lead->id}/activities")->assertForbidden();
    }

    public function test_duplicate_check_endpoint(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        Lead::factory()->for(User::factory()->withRole(Role::Salesperson))->create(['business_name' => 'Zzz Name', 'contact_phone' => '+254 711 222 333']);

        $this->api($john, ['registry:read'])->postJson('/api/v1/duplicates/check', ['phones' => ['0711222333']])
            ->assertOk()->assertJsonPath('data.0.name', 'Zzz Name')->assertJsonPath('data.0.reasons.0', 'Same phone number');
        $this->api($john, ['leads:read'])->postJson('/api/v1/duplicates/check', ['name' => 'Something Unrelated Entirely'])->assertOk()->assertJsonCount(0, 'data');
        $this->api($john, ['profile'])->postJson('/api/v1/duplicates/check', ['name' => 'x'])->assertForbidden();
    }
}
