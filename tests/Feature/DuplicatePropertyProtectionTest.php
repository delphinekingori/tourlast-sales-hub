<?php

namespace Tests\Feature;

use App\Enums\EngagementStage;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Livewire\Leads\Index as LeadsIndex;
use App\Livewire\Leads\Show as LeadsShow;
use App\Livewire\Registry\Form as RegistryForm;
use App\Models\Lead;
use App\Models\Onboarding;
use App\Models\PropertyEngagement;
use App\Models\User;
use App\Notifications\SmartAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class DuplicatePropertyProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_salesperson_sees_a_colleagues_lead_for_the_same_hotel(): void
    {
        $sarah = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Sarah Achieng']);
        $john = User::factory()->withRole(Role::Salesperson)->create();
        Lead::factory()->for($sarah)->create([
            'business_name' => 'ABC Hotel Nairobi', 'location' => 'Nairobi', 'status' => LeadStatus::Meeting, 'last_contacted_at' => now()->setDate(2026, 9, 18),
        ]);

        Livewire::actingAs($john)->test(LeadsIndex::class)
            ->call('openCreate')
            ->set('form.business_name', 'ABC Hotel')
            ->set('form.location', 'Nairobi')
            ->assertSee('Possible existing property found')
            ->assertSee('Being worked by Sarah Achieng')
            ->assertSee('Lead · Meeting')
            ->assertSee('18 Sep 2026')
            ->assertSee('Sarah Achieng is working on this')
            ->call('create')
            ->assertHasErrors('confirmDifferent');

        $this->assertSame(1, Lead::count());
    }

    public function test_every_identifier_is_checked(): void
    {
        $sarah = User::factory()->withRole(Role::Salesperson)->create();
        $john = User::factory()->withRole(Role::Salesperson)->create();
        Lead::factory()->for($sarah)->create([
            'business_name' => 'Zzz Unrelated Name', 'trading_name' => 'Coral Reef Stays', 'contact_phone' => '+254 711 222 333',
            'contact_email' => 'gm@coralreef.co.ke', 'website' => 'https://www.coralreef.co.ke', 'registration_number' => 'PVT-9', 'kra_pin' => 'P051234567X',
        ]);

        foreach ([
            ['form.business_name', 'Coral Reef Stays', 'Similar name'],
            ['form.trading_name', 'Coral Reef', 'Similar name'],
            ['form.contact_phone', '0711222333', 'Same phone number'],
            ['form.contact_email', 'GM@coralreef.co.ke', 'Same email'],
            ['form.website', 'coralreef.co.ke/rooms', 'Same website'],
            ['form.registration_number', 'PVT-9', 'Same registration number'],
            ['form.kra_pin', 'p051234567x', 'Same KRA PIN'],
        ] as [$field, $value, $reason]) {
            $component = Livewire::actingAs($john)->test(LeadsIndex::class)->call('openCreate');

            if ($field !== 'form.business_name') {
                $component->set('form.business_name', 'Something Else Entirely');
            }

            $component->set($field, $value)->assertSee('Zzz Unrelated Name')->assertSee($reason);
        }
    }

    public function test_continue_existing_engagement_links_the_lead_and_alerts_the_current_rep_and_managers(): void
    {
        Notification::fake();
        $mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambua']);
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $engagement = PropertyEngagement::factory()->forRep($mary)->stage(EngagementStage::ProposalSent)->create(['name' => 'PrideInn Paradise Beach Resort']);

        Livewire::actingAs($john)->test(LeadsIndex::class)
            ->call('openCreate')
            ->set('form.business_name', 'PrideInn Paradise')
            ->assertSee('Being worked by Mary Wambua')
            ->assertSee('Proposal sent')
            ->assertSee('Continue existing engagement')
            ->call('continueWith', 'registry', $engagement->id)
            ->assertHasNoErrors()
            ->assertRedirect();

        $lead = Lead::sole();
        $this->assertSame($john->id, $lead->user_id);
        $this->assertSame($engagement->id, $lead->property_engagement_id);
        $this->assertSame($mary->id, $engagement->fresh()->sales_rep_id, 'Continuing never reassigns the registry record.');
        Notification::assertSentTo($manager, SmartAlert::class);
    }

    public function test_continuing_your_own_lead_opens_it_instead_of_duplicating(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $lead = Lead::factory()->for($john)->create(['business_name' => 'Kilima Safaris']);

        Livewire::actingAs($john)->test(LeadsIndex::class)
            ->call('openCreate')
            ->set('form.business_name', 'Kilima Safaris Ltd')
            ->assertSee('Your lead')
            ->call('continueWith', 'lead', $lead->id)
            ->assertRedirect(route('leads.show', $lead));

        $this->assertSame(1, Lead::count());
    }

    public function test_overriding_a_duplicate_records_it_and_alerts_management(): void
    {
        Notification::fake();
        $sarah = User::factory()->withRole(Role::Salesperson)->create();
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        Lead::factory()->for($sarah)->create(['business_name' => 'Tembo Lodge', 'status' => LeadStatus::Contacted]);

        Livewire::actingAs($john)->test(LeadsIndex::class)
            ->call('openCreate')
            ->set('form.business_name', 'Tembo Lodge Naivasha')
            ->set('confirmDifferent', true)
            ->call('create')
            ->assertHasNoErrors();

        $this->assertSame(2, Lead::count());
        Notification::assertSentTo($manager, SmartAlert::class, fn (SmartAlert $alert) => $alert->type === 'duplicate_property');
    }

    public function test_tourlast_signups_count_as_existing_properties(): void
    {
        $mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambua']);
        Onboarding::factory()->forSalesperson($mary)->create(['property_name' => 'Nyota Beach Villas', 'contact_phone' => '+254722000111']);

        Livewire::actingAs(User::factory()->withRole(Role::Salesperson)->create())->test(LeadsIndex::class)
            ->call('openCreate')
            ->set('form.business_name', 'Different')
            ->set('form.contact_phone', '0722 000 111')
            ->assertSee('Nyota Beach Villas')
            ->assertSee('Referred by Mary Wambua')
            ->assertSee('tourlast.com signup');
    }

    public function test_no_warning_for_a_genuinely_new_property(): void
    {
        Lead::factory()->create(['business_name' => 'Kifaru Hotel']);

        Livewire::actingAs($john = User::factory()->withRole(Role::Salesperson)->create())->test(LeadsIndex::class)
            ->call('openCreate')
            ->set('form.business_name', 'Brand New Guesthouse Twiga')
            ->assertDontSee('Possible existing property')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertTrue(Lead::query()->where('user_id', $john->id)->exists());
    }

    public function test_editing_a_lead_warns_about_other_records_but_not_itself(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $sarah = User::factory()->withRole(Role::Salesperson)->create();
        $lead = Lead::factory()->for($john)->create(['business_name' => 'Duma Camp']);
        Lead::factory()->for($sarah)->create(['business_name' => 'Simba Heights']);

        Livewire::actingAs($john)->test(LeadsShow::class, ['lead' => $lead])
            ->call('openEdit')
            ->assertDontSee('Possible existing property')
            ->set('form.business_name', 'Simba Heights')
            ->assertSee('Possible existing property found');
    }

    public function test_the_registry_form_also_shows_matching_salesperson_leads(): void
    {
        $sarah = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Sarah Achieng']);
        Lead::factory()->for($sarah)->create(['business_name' => 'Malaika Beach Resort']);

        Livewire::actingAs(User::factory()->withRole(Role::SalesManager)->create())->test(RegistryForm::class)
            ->set('name', 'Malaika Beach Resort')
            ->assertSee('Sarah Achieng')
            ->assertSee('Matching leads or signups are shown for information');
    }
}
