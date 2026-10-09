<?php

namespace Tests\Feature\Travel\Packages;

use App\Enums\Role;
use App\Enums\Travel\PackageStatus;
use App\Enums\Travel\PackageVersionStatus;
use App\Livewire\Travel\Approvals\Index as Approvals;
use App\Livewire\Travel\Packages\Form;
use App\Livewire\Travel\Packages\Index;
use App\Livewire\Travel\Packages\Show;
use App\Models\MediaAsset;
use App\Models\Package;
use App\Models\PackageApproval;
use App\Models\ProviderContract;
use App\Models\TravelProvider;
use App\Models\User;
use Database\Seeders\Travel\PackagesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class PackagePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_travel_people_open_the_pages_and_others_are_refused(): void
    {
        $seller = User::factory()->withRole(Role::TravelSalesperson)->create();
        $package = Package::factory()->published()->create(['owner_id' => $seller->id, 'created_by' => $seller->id]);

        foreach (['travel.packages.index' => [], 'travel.packages.create' => [], 'travel.packages.show' => [$package], 'travel.packages.edit' => [$package]] as $route => $params) {
            $this->actingAs($seller)->get(route($route, $params))->assertOk();
        }

        foreach ([Role::SalesManager, Role::Hr, Role::Accounts, Role::Salesperson] as $role) {
            $this->actingAs(User::factory()->withRole($role)->create())->get(route('travel.packages.index'))->assertForbidden();
        }

        $this->actingAs($seller)->get(route('travel.approvals.index'))->assertForbidden();
        $this->actingAs(User::factory()->withRole(Role::SalesAdmin)->create())->get(route('travel.approvals.index'))->assertOk();
        $this->actingAs(User::factory()->withRole(Role::TravelSalesperson)->create())->get(route('travel.packages.edit', $package))->assertForbidden();
    }

    public function test_every_package_tab_renders(): void
    {
        $seller = User::factory()->withRole(Role::TravelSalesperson)->create();
        $package = Package::factory()->published()->create(['owner_id' => $seller->id, 'created_by' => $seller->id]);

        foreach (array_keys(Show::Tabs) as $tab) {
            $this->actingAs($seller)->get(route('travel.packages.show', ['package' => $package, 'tab' => $tab]))->assertOk();
        }
    }

    public function test_a_package_is_created_through_the_form(): void
    {
        $seller = User::factory()->withRole(Role::TravelSalesperson)->create(['name' => 'John Doe']);
        $provider = TravelProvider::factory()->create(['owner_id' => $seller->id]);
        $contract = ProviderContract::factory()->create(['travel_provider_id' => $provider->id]);
        $media = MediaAsset::factory()->create(['travel_provider_id' => $provider->id]);

        Livewire::actingAs($seller)->test(Form::class)
            ->set('form.name', 'Mara Balloon Morning')
            ->set('form.short_description', 'Sunrise balloon flight.')
            ->set('form.description', 'Balloon flight with champagne breakfast.')
            ->set('form.travel_provider_id', (string) $provider->id)
            ->assertSet('form.provider_contract_id', (string) $contract->id)
            ->set('form.destination', 'Masai Mara')
            ->set('form.default_capacity', '8')
            ->set('form.inclusions', ['Flight', 'Breakfast'])
            ->set('form.exclusions', ['Tips'])
            ->set('form.cancellation_policy', 'Full refund 7 days before.')
            ->set('form.refund_policy', 'Within 10 days.')
            ->set('form.adult_price', '55000')
            ->set('itinerary.0.title', 'Balloon flight')
            ->call('toggleMedia', $media->id)
            ->call('saveAndSubmit')
            ->assertHasNoErrors()
            ->assertRedirect();

        $package = Package::sole();
        $this->assertSame($seller->id, $package->created_by);
        $this->assertSame(PackageStatus::PendingApproval, $package->status);
        $this->assertSame(PackageVersionStatus::Submitted, $package->workingVersion->status);
        $this->assertSame($media->id, $package->media()->wherePivot('is_primary', true)->value('media_assets.id'));
    }

    public function test_approvals_queue_approves_and_requires_a_reason_to_reject(): void
    {
        $seller = User::factory()->withRole(Role::TravelSalesperson)->create();
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $package = Package::factory()->pendingApproval()->create(['owner_id' => $seller->id, 'created_by' => $seller->id]);
        $package->workingVersion->update(['submitted_at' => now(), 'submitted_by' => $seller->id]);

        Livewire::actingAs($admin)->test(Approvals::class)
            ->assertSee($package->name)
            ->call('review', $package->id)
            ->call('reject')
            ->assertHasErrors('reason')
            ->set('reason', 'Add a refund policy.')
            ->call('requestChanges')
            ->assertHasNoErrors();

        $this->assertSame(PackageVersionStatus::ChangesRequested, $package->fresh()->workingVersion->status);
        $this->assertSame(1, PackageApproval::count());
    }

    public function test_the_creator_never_sees_a_review_button_on_their_own_package(): void
    {
        $super = User::factory()->withRole(Role::SuperAdmin)->create();
        $package = Package::factory()->pendingApproval()->create(['owner_id' => $super->id, 'created_by' => $super->id]);

        Livewire::actingAs($super)->test(Approvals::class)
            ->assertSee('Your package')
            ->call('review', $package->id)
            ->call('approve')
            ->assertForbidden();
    }

    public function test_the_table_filters_and_actions_respect_permissions(): void
    {
        $seller = User::factory()->withRole(Role::TravelSalesperson)->create();
        $mine = Package::factory()->create(['owner_id' => $seller->id, 'created_by' => $seller->id, 'name' => 'Mine Safari']);
        $other = Package::factory()->approved()->create(['name' => 'Other Escape']);

        Livewire::actingAs($seller)->test(Index::class)
            ->assertSee('Mine Safari')->assertSee('Other Escape')
            ->set('owner', 'mine')
            ->assertSee('Mine Safari')->assertDontSee('Other Escape')
            ->call('archivePackage', $other->id)
            ->assertForbidden();

        $this->assertNull($other->fresh()->archived_at);
        $this->assertNotNull($mine);
    }

    public function test_the_seeder_creates_packages_in_every_state(): void
    {
        User::factory()->withRole(Role::TravelSalesperson)->create();
        $this->seed(PackagesSeeder::class);

        $this->assertSame(8, Package::count());
        $this->assertSame(2, Package::query()->where('status', PackageStatus::Draft)->count());
        $this->assertSame(3, Package::query()->where('status', PackageStatus::Published)->count());
        $this->assertSame(1, Package::query()->whereNotNull('live_version_id')->whereNotNull('working_version_id')->count());
    }
}
