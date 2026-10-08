<?php

namespace Tests\Feature;

use App\Actions\ApplyProviderRecord;
use App\Enums\EngagementEventType;
use App\Enums\EngagementStage;
use App\Enums\EngagementStatus;
use App\Enums\OnboardingStatus;
use App\Enums\Role;
use App\Integrations\Tourlast\ProviderRecord;
use App\Livewire\Leads\Index as LeadsIndex;
use App\Livewire\Registry\Form;
use App\Livewire\Registry\Index;
use App\Livewire\Registry\Show;
use App\Models\Lead;
use App\Models\Onboarding;
use App\Models\PropertyEngagement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PropertyEngagementRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_travel_salespeople_cannot_see_the_registry(): void
    {
        $engagement = PropertyEngagement::factory()->create();
        $travel = User::factory()->withRole(Role::TravelSalesperson)->create();

        $this->actingAs($travel)->get(route('registry.index'))->assertForbidden();
        $this->actingAs($travel)->get(route('registry.show', $engagement))->assertForbidden();
        $this->actingAs($travel)->get(route('registry.export'))->assertForbidden();
        $this->actingAs($travel)->get(route('travel.dashboard'))->assertOk()->assertDontSee('Property Engagement Registry');
    }

    public function test_every_property_and_back_office_role_can_open_the_registry(): void
    {
        $engagement = PropertyEngagement::factory()->create();

        foreach (array_filter(Role::cases(), fn (Role $role): bool => $role !== Role::TravelSalesperson) as $role) {
            $user = User::factory()->withRole($role)->create();
            $this->actingAs($user)->get(route('registry.index'))->assertOk();
            $this->actingAs($user)->get(route('registry.show', $engagement))->assertOk()->assertSee($engagement->name);
        }
    }

    public function test_managers_can_add_a_property_with_its_primary_contact(): void
    {
        foreach ([[Role::SuperAdmin, 'Kijani Gardens'], [Role::SalesAdmin, 'Tulia Retreat'], [Role::SalesManager, 'Faraja Springs']] as [$role, $name]) {
            $manager = User::factory()->withRole($role)->create();
            $rep = User::factory()->withRole(Role::Salesperson)->create();

            $this->fillForm(Livewire::actingAs($manager)->test(Form::class), $name, $rep)
                ->call('save')
                ->assertHasNoErrors()
                ->assertRedirect();

            $engagement = PropertyEngagement::query()->where('name', $name)->firstOrFail();
            $this->assertSame($rep->id, $engagement->sales_rep_id);
            $this->assertSame($manager->id, $engagement->created_by);
            $this->assertSame('Jane Owner', $engagement->primaryContact->name);
            $this->assertTrue($engagement->reps()->whereNull('ended_on')->where('user_id', $rep->id)->exists());
            $this->assertSame(EngagementEventType::Created, $engagement->events()->first()->type);
        }
    }

    public function test_the_form_suggests_regions_and_cities_already_in_the_registry(): void
    {
        PropertyEngagement::factory()->create(['region' => 'Kwale', 'city' => 'Diani']);
        PropertyEngagement::factory()->create(['region' => 'Kwale', 'city' => 'Ukunda']);
        $manager = User::factory()->withRole(Role::SalesManager)->create();

        $component = Livewire::actingAs($manager)->test(Form::class);

        $this->assertSame(['Kwale'], $component->instance()->knownRegions->all());
        $this->assertSame(['Diani', 'Ukunda'], $component->instance()->knownCities->all());
        $component->assertSeeHtml('<option value="Diani"></option>');
    }

    public function test_salespeople_cannot_create_edit_archive_or_export(): void
    {
        $salesperson = User::factory()->withRole(Role::Salesperson)->create();
        $engagement = PropertyEngagement::factory()->create();

        $this->actingAs($salesperson)->get(route('registry.create'))->assertForbidden();
        $this->actingAs($salesperson)->get(route('registry.edit', $engagement))->assertForbidden();
        $this->actingAs($salesperson)->get(route('registry.export'))->assertForbidden();
        $this->actingAs($salesperson)->get(route('registry.report'))->assertForbidden();

        Livewire::actingAs($salesperson)->test(Form::class)->assertForbidden();
        Livewire::actingAs($salesperson)->test(Form::class, ['engagement' => $engagement])->assertForbidden();

        Livewire::actingAs($salesperson)->test(Show::class, ['engagement' => $engagement->id])
            ->assertSee('Read only')
            ->assertDontSee('Log engagement')
            ->assertDontSee('Change rep');

        foreach (['archive', 'openLog', 'openReassign', 'openContact'] as $method) {
            Livewire::actingAs($salesperson)->test(Show::class, ['engagement' => $engagement->id])->call($method)->assertForbidden();
        }

        $this->assertNotSoftDeleted($engagement);
    }

    public function test_salespeople_cannot_change_stage_or_rep_through_crafted_requests(): void
    {
        $salesperson = User::factory()->withRole(Role::Salesperson)->create();
        $other = User::factory()->withRole(Role::Salesperson)->create();
        $engagement = PropertyEngagement::factory()->stage(EngagementStage::Contacted)->create();
        $originalRep = $engagement->sales_rep_id;

        $show = fn () => Livewire::actingAs($salesperson)->test(Show::class, ['engagement' => $engagement->id]);
        $show()->set('log', ['type' => 'call', 'rep' => (string) $other->id, 'happened_on' => now()->toDateString(), 'summary' => 'x', 'notes' => '', 'stage' => 'live', 'status' => 'won', 'next_action' => '', 'next_action_on' => ''])
            ->call('saveLog')
            ->assertForbidden();
        $show()->set('newRep', (string) $other->id)->call('saveReassign')->assertForbidden();
        $show()->call('linkLead', 1)->assertForbidden();

        $engagement->refresh();
        $this->assertSame(EngagementStage::Contacted, $engagement->stage);
        $this->assertSame($originalRep, $engagement->sales_rep_id);
    }

    public function test_hr_and_accounts_are_read_only(): void
    {
        $engagement = PropertyEngagement::factory()->create();

        foreach ([Role::Hr, Role::Accounts] as $role) {
            $user = User::factory()->withRole($role)->create();
            $this->actingAs($user)->get(route('registry.create'))->assertForbidden();
            $this->actingAs($user)->get(route('registry.export'))->assertForbidden();
            Livewire::actingAs($user)->test(Show::class, ['engagement' => $engagement->id])->call('archive')->assertForbidden();
        }
    }

    public function test_salesperson_can_search_filter_and_sort_the_registry(): void
    {
        $salesperson = User::factory()->withRole(Role::Salesperson)->create();
        $mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambua']);
        PropertyEngagement::factory()->forRep($mary)->stage(EngagementStage::ProposalSent)->create(['name' => 'ABC Hotel', 'city' => 'Nairobi', 'region' => 'Nairobi', 'property_type' => 'hotel']);
        PropertyEngagement::factory()->stage(EngagementStage::Contacted, EngagementStatus::Stalled)->create(['name' => 'XYZ Apartments', 'city' => 'Mombasa', 'region' => 'Mombasa', 'property_type' => 'apartment']);
        PropertyEngagement::factory()->stage(EngagementStage::OnboardingStarted)->create(['name' => 'Safari Adventures', 'city' => 'Narok', 'region' => 'Narok', 'property_type' => 'tour']);

        Livewire::actingAs($salesperson)->test(Index::class)
            ->assertSee('ABC Hotel')->assertSee('XYZ Apartments')->assertSee('Safari Adventures')
            ->assertDontSee('Add property')->assertDontSee('Export Excel')
            ->set('search', 'Mombasa')
            ->assertSee('XYZ Apartments')->assertDontSee('ABC Hotel')
            ->set('search', 'Mary Wambua')
            ->assertSee('ABC Hotel')->assertDontSee('XYZ Apartments')
            ->set('search', '')
            ->set('status', 'stalled')
            ->assertSee('XYZ Apartments')->assertDontSee('Safari Adventures')
            ->call('clearFilters')
            ->set('type', 'tour')
            ->assertSee('Safari Adventures')->assertDontSee('ABC Hotel')
            ->call('clearFilters')
            ->call('sortBy', 'name')
            ->assertSeeInOrder(['ABC Hotel', 'Safari Adventures', 'XYZ Apartments'])
            ->call('sortBy', 'stage')
            ->assertSeeInOrder(['XYZ Apartments', 'ABC Hotel', 'Safari Adventures']);
    }

    public function test_search_finds_properties_by_contact_phone(): void
    {
        $engagement = PropertyEngagement::factory()->create(['name' => 'Phone Lookup Lodge']);
        $engagement->contacts()->update(['is_primary' => false]);
        $engagement->contacts()->create(['name' => 'Ali', 'title' => 'Owner', 'phone' => '0722 123 456', 'is_primary' => true]);

        Livewire::actingAs(User::factory()->withRole(Role::Salesperson)->create())->test(Index::class)
            ->set('search', '+254722123456')
            ->assertSee('Phone Lookup Lodge');
    }

    public function test_duplicates_are_detected_and_block_creation_until_confirmed(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $rep = User::factory()->withRole(Role::Salesperson)->create();
        $existing = PropertyEngagement::factory()->create(['name' => 'PrideInn Paradise Beach Resort', 'city' => 'Mombasa']);

        $form = $this->fillForm(Livewire::actingAs($manager)->test(Form::class), 'PrideInn Paradise', $rep)
            ->set('city', 'Mombasa')
            ->assertSee('Possible existing property')
            ->assertSee('PrideInn Paradise Beach Resort')
            ->assertSee('Similar name, same town')
            ->call('save')
            ->assertHasErrors('confirmDifferent');

        $this->assertSame(1, PropertyEngagement::count());

        $form->set('confirmDifferent', true)->call('save')->assertHasNoErrors();

        $created = PropertyEngagement::query()->where('name', 'PrideInn Paradise')->firstOrFail();
        $this->assertSame([$existing->id], $created->events()->first()->changes['duplicate_override']);
    }

    public function test_duplicates_match_on_phone_website_email_registration_and_kra(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $existing = PropertyEngagement::factory()->create([
            'name' => 'Totally Different Name', 'website' => 'https://www.samehost.co.ke/about',
            'registration_number' => 'PVT-123', 'kra_pin' => 'P051234567X',
        ]);
        $existing->contacts()->first()->update(['phone' => '+254711000111', 'email' => 'gm@samehost.co.ke']);

        $cases = [
            ['contact_phone', '0711 000 111', 'Same phone number'],
            ['website', 'samehost.co.ke', 'Same website'],
            ['contact_email', 'GM@samehost.co.ke', 'Same email'],
            ['registration_number', 'PVT-123', 'Same registration number'],
            ['kra_pin', 'p051234567x', 'Same KRA PIN'],
        ];

        foreach ($cases as [$field, $value, $reason]) {
            Livewire::actingAs($manager)->test(Form::class)
                ->set('name', 'Brand New Place')
                ->set($field, $value)
                ->assertSee('Totally Different Name')
                ->assertSee($reason);
        }
    }

    public function test_a_manager_can_edit_and_every_change_is_audited(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $john = User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);
        $mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambua']);
        $engagement = PropertyEngagement::factory()->forRep($john)->stage(EngagementStage::Contacted)->create(['city' => 'Nairobi', 'property_type' => 'hotel', 'star_rating' => '4']);

        Livewire::actingAs($manager)->test(Form::class, ['engagement' => $engagement])
            ->set('city', 'Nakuru')
            ->set('stage', EngagementStage::ProposalSent->value)
            ->set('status', EngagementStatus::Stalled->value)
            ->set('sales_rep_id', (string) $mary->id)
            ->set('rep_reason', 'territory')
            ->call('save')
            ->assertHasNoErrors();

        $engagement->refresh();
        $this->assertSame('Nakuru', $engagement->city);
        $this->assertSame(EngagementStage::ProposalSent, $engagement->stage);
        $this->assertSame(EngagementStatus::Stalled, $engagement->status);
        $this->assertSame($mary->id, $engagement->sales_rep_id);
        $this->assertSame($manager->id, $engagement->updated_by);

        $edited = $engagement->events()->where('type', EngagementEventType::Edited)->first();
        $this->assertSame([['field' => 'city', 'label' => 'City / town', 'from' => 'Nairobi', 'to' => 'Nakuru']], $edited->changes);
        $this->assertDatabaseHas('property_engagement_events', ['property_engagement_id' => $engagement->id, 'type' => 'stage_changed', 'from_value' => 'contacted', 'to_value' => 'proposal_sent', 'recorded_by' => $manager->id]);
        $this->assertDatabaseHas('property_engagement_events', ['property_engagement_id' => $engagement->id, 'type' => 'status_changed', 'from_value' => 'active', 'to_value' => 'stalled']);
        $this->assertDatabaseHas('property_engagement_events', ['property_engagement_id' => $engagement->id, 'type' => 'rep_changed', 'from_value' => 'John Doe', 'to_value' => 'Mary Wambua']);
    }

    public function test_history_is_preserved_across_salespeople(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $john = User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);
        $mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambua']);
        $engagement = PropertyEngagement::factory()->forRep($john)->create(['first_engaged_on' => now()->subMonth()]);

        $show = Livewire::actingAs($manager)->test(Show::class, ['engagement' => $engagement->id]);
        $show->call('openLog')
            ->set('log.type', 'call')->set('log.summary', 'Initial contact')->set('log.notes', 'GM unavailable. Spoke with Sales Manager.')
            ->set('log.happened_on', now()->subDays(10)->toDateString())
            ->call('saveLog')->assertHasNoErrors();

        $show->call('openLog')
            ->set('log.type', 'meeting')->set('log.rep', (string) $mary->id)->set('log.summary', 'Meeting completed')
            ->set('log.notes', 'GM requested a commercial proposal.')->set('log.stage', EngagementStage::MeetingCompleted->value)
            ->call('saveLog')->assertHasNoErrors();

        $engagement->refresh();
        $this->assertSame($mary->id, $engagement->sales_rep_id);
        $this->assertSame(EngagementStage::MeetingCompleted, $engagement->stage);
        $this->assertTrue($engagement->last_engaged_on->isToday());
        $this->assertSame([$mary->id, $john->id], $engagement->reps()->pluck('user_id')->all());
        $this->assertNotNull($engagement->reps()->where('user_id', $john->id)->value('ended_on'));

        $johnsCall = $engagement->events()->where('type', EngagementEventType::Call)->first();
        $this->assertSame($john->id, $johnsCall->sales_rep_id);
        $this->assertSame('GM unavailable. Spoke with Sales Manager.', $johnsCall->notes);

        Livewire::actingAs(User::factory()->withRole(Role::Salesperson)->create())
            ->test(Show::class, ['engagement' => $engagement->id])
            ->assertSee('Mary Wambua')->assertSee('John Doe')
            ->assertSee('GM requested a commercial proposal.')
            ->assertSee('GM unavailable. Spoke with Sales Manager.')
            ->assertSee('Previous');
    }

    public function test_archiving_keeps_history_and_hides_the_record_from_salespeople(): void
    {
        $manager = User::factory()->withRole(Role::SalesAdmin)->create();
        $engagement = PropertyEngagement::factory()->create(['name' => 'Archived Lodge']);

        Livewire::actingAs($manager)->test(Show::class, ['engagement' => $engagement->id])->call('archive');

        $this->assertSoftDeleted($engagement);
        $this->assertDatabaseHas('property_engagement_events', ['property_engagement_id' => $engagement->id, 'type' => 'archived']);
        Livewire::actingAs(User::factory()->withRole(Role::Salesperson)->create())->test(Index::class)->assertDontSee('Archived Lodge');
        $this->actingAs(User::factory()->withRole(Role::Salesperson)->create())->get(route('registry.show', $engagement->id))->assertForbidden();

        Livewire::actingAs($manager)->test(Index::class)->set('archived', true)->assertSee('Archived Lodge');
        Livewire::actingAs($manager)->test(Show::class, ['engagement' => $engagement->id])->call('restore');
        $this->assertNotSoftDeleted($engagement);
    }

    public function test_contacts_can_be_added_and_removed_with_history(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $engagement = PropertyEngagement::factory()->create();

        $show = Livewire::actingAs($manager)->test(Show::class, ['engagement' => $engagement->id])
            ->call('openContact')
            ->set('contact.name', 'Mary Doe')->set('contact.title', 'Sales Manager')->set('contact.phone', '+254700111222')
            ->call('saveContact')->assertHasNoErrors();

        $contact = $engagement->contacts()->where('name', 'Mary Doe')->firstOrFail();
        $this->assertFalse($contact->is_decision_maker);

        $show->call('removeContact', $contact->id);
        $this->assertModelMissing($contact);
        $this->assertDatabaseHas('property_engagement_events', ['property_engagement_id' => $engagement->id, 'type' => 'contact_removed']);
    }

    public function test_leads_and_onboardings_link_to_the_registry_and_sync_its_stage(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $engagement = PropertyEngagement::factory()->forRep($john)->stage(EngagementStage::OnboardingStarted)->create(['name' => 'Kifaru Lodge']);
        $phone = $engagement->primaryContact->phone;
        $lead = Lead::factory()->for($john)->create(['business_name' => 'Kifaru Lodge', 'contact_phone' => $phone]);
        $onboarding = Onboarding::factory()->forSalesperson($john)->create(['property_name' => 'Kifaru Lodge', 'status' => OnboardingStatus::UnderReview]);
        $lead->update(['onboarding_id' => $onboarding->id]);

        Livewire::actingAs($manager)->test(Show::class, ['engagement' => $engagement->id])
            ->assertSee('Possible matching leads')
            ->call('linkLead', $lead->id);

        $this->assertSame($engagement->id, $lead->fresh()->property_engagement_id);
        $this->assertSame($engagement->id, $onboarding->fresh()->property_engagement_id);
        $this->assertSame(EngagementStage::Verification, $engagement->fresh()->stage);

        // A later tourlast.com update moves the registry to Live.
        app(ApplyProviderRecord::class)->handle(new ProviderRecord(
            propertyId: $onboarding->tourlast_property_id,
            refCode: $onboarding->ref_code,
            propertyName: 'Kifaru Lodge',
            propertyType: 'lodge',
            location: null,
            contactName: null,
            contactPhone: null,
            contactEmail: null,
            status: OnboardingStatus::Active,
            submittedAt: $onboarding->submitted_at?->toImmutable(),
            approvedAt: now()->subDay()->toImmutable(),
            activeAt: now()->toImmutable(),
            rejectedAt: null,
            updatedAt: now()->toImmutable(),
        ), 'test');

        $engagement->refresh();
        $this->assertSame(EngagementStage::Live, $engagement->stage);
        $this->assertSame(EngagementStatus::Won, $engagement->status);
        $this->assertDatabaseHas('property_engagement_events', ['property_engagement_id' => $engagement->id, 'type' => 'onboarding', 'to_value' => 'live']);
    }

    public function test_salesperson_adding_a_lead_is_warned_about_an_existing_registry_record(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $engagement = PropertyEngagement::factory()->create(['name' => 'Milele Beach Hotel']);

        Livewire::actingAs($john)->test(LeadsIndex::class)
            ->call('openCreate')
            ->set('form.business_name', 'Milele Beach')
            ->assertSee('Possible existing property found')
            ->assertSee('Milele Beach Hotel')
            ->call('continueWith', 'registry', $engagement->id)
            ->assertHasNoErrors();

        $this->assertSame($engagement->id, Lead::query()->where('business_name', 'Milele Beach')->value('property_engagement_id'));
        $this->assertSame(0, $engagement->events()->where('type', '!=', 'created')->count());
    }

    public function test_managers_can_download_excel_and_the_report(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        PropertyEngagement::factory()->count(3)->create(['first_engaged_on' => now()->startOfMonth()]);

        $excel = $this->actingAs($manager)->get(route('registry.export'));
        $excel->assertOk();
        $this->assertStringContainsString('.xlsx', (string) $excel->headers->get('content-disposition'));

        $pdf = $this->actingAs($manager)->get(route('registry.report', ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString()]));
        $pdf->assertOk();
        $this->assertStringContainsString('.pdf', (string) $pdf->headers->get('content-disposition'));
    }

    public function test_navigation_shows_the_property_acquisition_section(): void
    {
        $this->actingAs(User::factory()->withRole(Role::Salesperson)->create())->get(route('dashboard'))
            ->assertSee('Property acquisition')->assertSee('Property Engagement Registry')->assertSee('Referral Center');

        $this->actingAs(User::factory()->withRole(Role::Hr)->create())->get(route('dashboard'))
            ->assertSee('Property Engagement Registry')->assertDontSee('Referral Center');
    }

    public function test_referral_center_is_for_salespeople(): void
    {
        $this->actingAs(User::factory()->withRole(Role::Salesperson)->create())->get(route('referrals.index'))->assertOk()->assertSee('Referral funnel');
        $this->actingAs(User::factory()->withRole(Role::Accounts)->create())->get(route('referrals.index'))->assertForbidden();
    }

    private function fillForm(mixed $component, string $name, User $rep): mixed
    {
        return $component
            ->set('name', $name)
            ->set('property_type', 'hotel')
            ->set('country', 'Kenya')
            ->set('region', 'Nairobi')
            ->set('city', 'Nairobi')
            ->set('contact_name', 'Jane Owner')
            ->set('contact_title', 'Owner')
            ->set('contact_phone', '+254 700 '.random_int(100000, 999999))
            ->set('sales_rep_id', (string) $rep->id)
            ->set('first_engaged_on', now()->subWeek()->toDateString())
            ->set('stage', EngagementStage::Contacted->value)
            ->set('status', EngagementStatus::Active->value);
    }
}
