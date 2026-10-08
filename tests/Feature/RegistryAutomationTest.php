<?php

namespace Tests\Feature;

use App\Actions\ApplyProviderRecord;
use App\Actions\AssignOnboarding;
use App\Actions\IssueReferralCode;
use App\Enums\EngagementSource;
use App\Enums\EngagementStage;
use App\Enums\EngagementStatus;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Incentives\AccountPoints;
use App\Integrations\Tourlast\ProviderRecordMapper;
use App\Livewire\Leads\Show as LeadShow;
use App\Livewire\Registry\Index;
use App\Models\Lead;
use App\Models\Onboarding;
use App\Models\PartnerAccount;
use App\Models\PropertyEngagement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RegistryAutomationTest extends TestCase
{
    use RefreshDatabase;

    private User $mary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambui']);
        app(IssueReferralCode::class)->handle($this->mary);
        $this->mary->refresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function sync(array $overrides = []): void
    {
        app(ApplyProviderRecord::class)->handle(app(ProviderRecordMapper::class)->fromArray([
            'property_id' => 'TL-90001',
            'account_id' => 'H-9',
            'legal_name' => 'Kijani Gardens Ltd',
            'property_name' => 'Kijani Gardens',
            'property_type' => 'hotel',
            'category' => 'stay',
            'inventory_count' => 20,
            'ref_code' => $this->mary->referralCode->code,
            'location' => 'Diani, Kwale, Kenya',
            'contact_name' => 'Jane Owner',
            'contact_email' => 'jane@kijani.example.com',
            'contact_phone' => '+254 712 345 678',
            'status' => 'submitted',
            'submitted_at' => now()->subDays(2)->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            ...$overrides,
        ]), 'sync');
    }

    public function test_a_signup_credited_to_a_salesperson_is_added_to_the_registry(): void
    {
        $this->sync();

        $engagement = PropertyEngagement::sole();
        $this->assertSame('Kijani Gardens', $engagement->name);
        $this->assertSame('TL-90001', $engagement->tourlast_property_id);
        $this->assertTrue($engagement->salesRep->is($this->mary));
        $this->assertSame(EngagementSource::Referral, $engagement->source);
        $this->assertSame(EngagementStage::OnboardingSubmitted, $engagement->stage);
        $this->assertSame(EngagementStatus::Active, $engagement->status);
        $this->assertSame(['Kenya', 'Kwale', 'Diani'], [$engagement->country, $engagement->region, $engagement->city]);
        $this->assertSame('jane@kijani.example.com', $engagement->primaryContact->email);
        $this->assertTrue($engagement->reps()->whereNull('ended_on')->where('user_id', $this->mary->id)->exists());
        $this->assertSame($engagement->id, Onboarding::sole()->property_engagement_id);
    }

    public function test_the_record_follows_the_signup_and_is_not_created_twice(): void
    {
        $this->sync();
        $this->sync(['status' => 'active', 'approved_at' => now()->toIso8601String(), 'active_at' => now()->toIso8601String(), 'updated_at' => now()->addMinute()->toIso8601String()]);

        $engagement = PropertyEngagement::sole();
        $this->assertSame(EngagementStage::Live, $engagement->stage);
        $this->assertSame(EngagementStatus::Won, $engagement->status);
    }

    public function test_a_signup_without_a_salesperson_stays_out_until_it_is_assigned(): void
    {
        $this->sync(['ref_code' => null]);

        $this->assertSame(0, PropertyEngagement::count());

        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        app(AssignOnboarding::class)->handle(Onboarding::sole(), $this->mary, $admin, 'Mary signed them up in person');

        $engagement = PropertyEngagement::sole();
        $this->assertTrue($engagement->salesRep->is($this->mary));
        $this->assertSame($engagement->id, Onboarding::sole()->property_engagement_id);
    }

    public function test_a_signup_joins_the_record_a_manager_already_added_for_the_same_business(): void
    {
        $existing = PropertyEngagement::factory()->create(['name' => 'Kijani  Gardens', 'city' => 'Diani', 'tourlast_property_id' => null, 'stage' => EngagementStage::Negotiation]);

        $this->sync();

        $this->assertSame(1, PropertyEngagement::count());
        $this->assertSame($existing->id, Onboarding::sole()->property_engagement_id);
        $this->assertSame('TL-90001', $existing->fresh()->tourlast_property_id);
        $this->assertSame(EngagementStage::OnboardingSubmitted, $existing->fresh()->stage);
    }

    public function test_a_record_a_manager_archived_is_not_brought_back(): void
    {
        $this->sync();
        PropertyEngagement::sole()->delete();

        $this->sync(['status' => 'under_review', 'updated_at' => now()->addMinute()->toIso8601String()]);

        $this->assertSame(0, PropertyEngagement::count());
        $this->assertSame(1, PropertyEngagement::onlyTrashed()->count());
    }

    public function test_a_lead_can_be_added_to_the_registry_in_one_click(): void
    {
        $lead = Lead::factory()->for($this->mary)->create([
            'business_name' => 'Tulia Retreat', 'property_type' => 'lodge', 'location' => 'Nanyuki, Kenya',
            'contact_name' => 'Ken Ole', 'contact_phone' => '+254 722 111 222', 'status' => LeadStatus::Meeting,
        ]);

        Livewire::actingAs($this->mary)->test(LeadShow::class, ['lead' => $lead])->call('addToRegistry')->assertHasNoErrors();

        $engagement = PropertyEngagement::sole();
        $this->assertSame($engagement->id, $lead->fresh()->property_engagement_id);
        $this->assertSame('Tulia Retreat', $engagement->name);
        $this->assertTrue($engagement->salesRep->is($this->mary));
        $this->assertSame(EngagementStage::MeetingScheduled, $engagement->stage);
        $this->assertSame('Ken Ole', $engagement->primaryContact->name);
        $this->assertSame('Nanyuki', $engagement->city);
    }

    public function test_adding_a_lead_that_looks_like_an_existing_record_asks_first(): void
    {
        PropertyEngagement::factory()->create(['name' => 'Tulia Retreat', 'city' => 'Nanyuki']);
        $lead = Lead::factory()->for($this->mary)->create(['business_name' => 'Tulia Retreat', 'location' => 'Nanyuki, Kenya']);

        $component = Livewire::actingAs($this->mary)->test(LeadShow::class, ['lead' => $lead])->call('addToRegistry');

        $this->assertSame(1, PropertyEngagement::count());
        $this->assertNull($lead->fresh()->property_engagement_id);
        $this->assertCount(1, $component->get('registryMatches'));

        $component->call('addToRegistry', true);

        $this->assertSame(2, PropertyEngagement::count());
        $this->assertNotNull($lead->fresh()->property_engagement_id);
    }

    public function test_only_the_owner_can_add_a_lead_to_the_registry(): void
    {
        $lead = Lead::factory()->for($this->mary)->create();
        $other = User::factory()->withRole(Role::Salesperson)->create();

        Livewire::actingAs($other)->test(LeadShow::class, ['lead' => $lead])->assertForbidden();
        $this->assertSame(0, PropertyEngagement::count());
    }

    public function test_failing_the_fourteen_day_review_marks_the_registry_record_lost(): void
    {
        $this->sync(['status' => 'active', 'approved_at' => now()->toIso8601String(), 'active_at' => now()->toIso8601String()]);
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();

        app(AccountPoints::class)->failReview(PartnerAccount::sole(), $admin, 'Partner requested closure');

        $engagement = PropertyEngagement::sole();
        $this->assertSame(EngagementStatus::Lost, $engagement->status);
        $this->assertSame('14-day review failed: Partner requested closure', $engagement->outcome_notes);

        $this->sync(['status' => 'active', 'approved_at' => now()->toIso8601String(), 'active_at' => now()->toIso8601String(), 'inventory_count' => 25, 'updated_at' => now()->addMinute()->toIso8601String()]);
        $this->assertSame(EngagementStatus::Lost, $engagement->fresh()->status);
    }

    public function test_the_registry_shows_what_needs_attention(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $open = fn (array $state) => PropertyEngagement::factory()->create(['status' => EngagementStatus::Active, 'sales_rep_id' => $this->mary->id, 'next_action_on' => now()->addDays(3), 'last_engaged_on' => now(), ...$state]);
        $open(['name' => 'Overdue Lodge', 'next_action_on' => now()->subDays(2)]);
        $open(['name' => 'Quiet Resort', 'last_engaged_on' => now()->subDays(40)]);
        $open(['name' => 'Nobody Hotel', 'sales_rep_id' => null]);
        $open(['name' => 'Fine Villas']);
        PropertyEngagement::factory()->create(['name' => 'Gone Camp', 'status' => EngagementStatus::Lost, 'next_action_on' => now()->subDays(9), 'sales_rep_id' => null]);

        $component = Livewire::actingAs($manager)->test(Index::class)
            ->assertSee('Needs attention')
            ->assertSee('Next action overdue')
            ->assertSee('Fine Villas');

        $component->set('attention', 'overdue')->assertSee('Overdue Lodge')->assertDontSee('Fine Villas')->assertDontSee('Gone Camp');
        $component->set('attention', 'idle')->assertSee('Quiet Resort')->assertDontSee('Overdue Lodge');
        $component->set('attention', 'unassigned')->assertSee('Nobody Hotel')->assertDontSee('Gone Camp')->assertDontSee('Quiet Resort');
        $component->call('clearFilters')->assertSet('attention', '');
    }
}
