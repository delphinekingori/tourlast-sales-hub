<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Enums\Travel\PackageVersionStatus;
use App\Models\FlightBooking;
use App\Models\Package;
use App\Models\PackageBooking;
use App\Models\PackageDeparture;
use App\Models\TravelPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class TravelApiTest extends TestCase
{
    use MakesApiRequests, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_travel_read_scope_is_required(): void
    {
        $travel = User::factory()->withRole(Role::TravelSalesperson)->create();

        $this->api($travel, ['profile'])->getJson('/api/v1/travel/bookings')
            ->assertForbidden()
            ->assertJsonPath('required_scopes', ['travel:read']);

        $this->api($travel, ['travel:read'])->getJson('/api/v1/travel/dashboard')->assertOk()->assertJsonPath('data.scope', 'mine');
    }

    public function test_sales_managers_and_hr_are_refused_even_with_the_scope(): void
    {
        foreach ([Role::SalesManager, Role::Hr, Role::Salesperson] as $role) {
            $user = User::factory()->withRole($role)->create();

            foreach (['dashboard', 'flights', 'packages', 'bookings', 'payments', 'reports'] as $path) {
                $this->api($user)->getJson('/api/v1/travel/'.$path)->assertForbidden();
            }
        }
    }

    public function test_salesperson_sees_only_their_own_bookings_and_masked_contacts_elsewhere(): void
    {
        $mine = PackageBooking::factory()->create();
        $theirs = PackageBooking::factory()->create();

        $this->api($mine->salesperson, ['travel:read'])->getJson('/api/v1/travel/bookings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $mine->reference)
            ->assertJsonPath('data.0.client.email', $mine->client->email);

        $this->api($mine->salesperson, ['travel:read'])->getJson('/api/v1/travel/bookings/'.$theirs->id)->assertNotFound();
    }

    public function test_package_creator_cannot_approve_their_own_package_through_the_api(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $package = Package::factory()->pendingApproval()->create();
        $package->forceFill(['owner_id' => $admin->id, 'created_by' => $admin->id])->save();

        $this->api($admin, ['travel:write'])->postJson("/api/v1/travel/packages/{$package->id}/review", ['decision' => 'approved'])
            ->assertForbidden();

        $other = User::factory()->withRole(Role::SalesAdmin)->create();
        $this->api($other, ['travel:write'])->postJson("/api/v1/travel/packages/{$package->id}/review", ['decision' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.working_version.status', PackageVersionStatus::SalesAdminApproved->value);
    }

    public function test_travel_salesperson_cannot_review_or_publish_an_unapproved_package(): void
    {
        $package = Package::factory()->pendingApproval()->create();
        $owner = $package->owner;

        $this->api($owner, ['travel:write'])->postJson("/api/v1/travel/packages/{$package->id}/review", ['decision' => 'approved'])->assertForbidden();
        $this->api($owner, ['travel:write'])->postJson("/api/v1/travel/packages/{$package->id}/publish", ['channel' => 'tourlast.com'])->assertUnprocessable();
    }

    public function test_reject_needs_a_reason(): void
    {
        $package = Package::factory()->pendingApproval()->create();

        $this->api(User::factory()->withRole(Role::SalesAdmin)->create(), ['travel:write'])
            ->postJson("/api/v1/travel/packages/{$package->id}/review", ['decision' => 'rejected'])
            ->assertUnprocessable();
    }

    public function test_overbooking_is_refused_through_the_api(): void
    {
        $departure = PackageDeparture::factory()->create(['capacity' => 2]);
        $owner = $departure->package->owner;

        $this->api($owner, ['travel:write'])->postJson('/api/v1/travel/bookings', [
            'package_id' => $departure->package_id,
            'package_departure_id' => $departure->id,
            'client_name' => 'Wanjiru Kamau',
            'client_phone' => '0712345678',
            'adults' => 3,
        ])->assertUnprocessable();

        $this->api($owner, ['travel:write'])->postJson('/api/v1/travel/bookings', [
            'package_id' => $departure->package_id,
            'package_departure_id' => $departure->id,
            'client_name' => 'Wanjiru Kamau',
            'client_phone' => '0712345678',
            'adults' => 2,
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.travelers', 2);
    }

    public function test_mpesa_request_works_in_sandbox(): void
    {
        config(['travel.mpesa.driver' => 'sandbox']);
        $booking = PackageBooking::factory()->create(['amount_total' => 90000]);

        $this->api($booking->salesperson, ['travel:write'])->postJson("/api/v1/travel/bookings/{$booking->id}/mpesa", ['phone' => '0712345678', 'amount' => 5000])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.channel', 'stk');

        $this->assertSame(1, TravelPayment::query()->where('package_booking_id', $booking->id)->count());
    }

    public function test_flights_are_read_only(): void
    {
        $flight = FlightBooking::factory()->create();
        $travel = User::factory()->withRole(Role::TravelSalesperson)->create();

        $this->api($travel)->getJson('/api/v1/travel/flights/'.$flight->id)->assertOk()->assertJsonMissingPath('data.markup_amount');

        $writes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/travel/flights'))
            ->reject(fn ($route) => $route->methods() === ['GET', 'HEAD']);
        $this->assertCount(0, $writes);

        $this->api($travel)->patchJson('/api/v1/travel/flights/'.$flight->id, ['booking_status' => 'cancelled'])->assertStatus(405);
    }

    public function test_accounts_see_payments_but_cannot_create_packages(): void
    {
        TravelPayment::factory()->create();
        $accounts = User::factory()->withRole(Role::Accounts)->create();

        $this->api($accounts)->getJson('/api/v1/travel/payments')->assertOk()->assertJsonCount(1, 'data');
        $this->api($accounts)->postJson('/api/v1/travel/packages', ['name' => 'Sneaky'])->assertForbidden();
        $this->api($accounts)->getJson('/api/v1/travel/packages')->assertForbidden();
    }

    public function test_package_financials_are_hidden_from_other_travel_salespeople(): void
    {
        $package = Package::factory()->published()->create();
        $other = User::factory()->withRole(Role::TravelSalesperson)->create();

        $this->api($other, ['travel:read'])->getJson('/api/v1/travel/packages/'.$package->id)
            ->assertOk()
            ->assertJsonPath('data.live_version.adult_price', '45000.00')
            ->assertJsonMissingPath('data.live_version.net_provider_price');

        $this->api($package->owner, ['travel:read'])->getJson('/api/v1/travel/packages/'.$package->id)
            ->assertJsonPath('data.live_version.net_provider_price', '38000.00');
    }

    public function test_travel_follow_up_is_scheduled_on_a_visible_record(): void
    {
        $package = Package::factory()->published()->create();

        $this->api($package->owner, ['travel:write'])->postJson('/api/v1/travel/schedule', [
            'subject' => 'package:'.$package->id,
            'type' => 'package_review',
            'task' => 'Review rates',
            'due_at' => now()->addDay()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.subject_label', $package->name);

        $other = User::factory()->withRole(Role::TravelSalesperson)->create();
        $this->api($other, ['travel:write'])->postJson('/api/v1/travel/schedule', [
            'subject' => 'package:'.$package->id,
            'type' => 'package_review',
            'task' => 'Not mine',
            'due_at' => now()->addDay()->toDateString(),
        ])->assertUnprocessable();
    }

    public function test_reports_and_targets_render(): void
    {
        PackageBooking::factory()->paid()->create();
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();

        $this->api($admin, ['travel:read'])->getJson('/api/v1/travel/reports?period=year')->assertOk()->assertJsonStructure(['data' => ['flights', 'tours', 'providers', 'approvals', 'salespeople']]);
        $this->api($admin, ['travel:read'])->getJson('/api/v1/travel/targets')->assertOk();
        $this->api($admin, ['travel:read'])->getJson('/api/v1/travel/dashboard?scope=team')->assertOk()->assertJsonPath('data.scope', 'team');
    }
}
