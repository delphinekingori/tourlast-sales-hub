<?php

namespace Tests\Feature\Travel\Bookings;

use App\Actions\Travel\Bookings\ChangeBookingStatus;
use App\Actions\Travel\Bookings\CreatePackageBooking;
use App\Actions\Travel\Bookings\DecideCancellation;
use App\Actions\Travel\Bookings\ExpireBookingHolds;
use App\Actions\Travel\Bookings\ManageRefund;
use App\Actions\Travel\Bookings\RequestCancellation;
use App\Actions\Travel\Bookings\SendPreTripReminders;
use App\Actions\Travel\Departures\SaveDeparture;
use App\Actions\Travel\RefreshBookingPayment;
use App\Actions\Travel\Resources\AssignTripResources;
use App\Enums\Role;
use App\Enums\Travel\BookingPaymentStatus;
use App\Enums\Travel\CancellationStatus;
use App\Enums\Travel\RefundStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Livewire\Travel\Bookings\Create;
use App\Livewire\Travel\Bookings\Show;
use App\Livewire\Travel\Departures\Index as Inventory;
use App\Models\Driver;
use App\Models\Influencer;
use App\Models\InfluencerCode;
use App\Models\Package;
use App\Models\PackageBooking;
use App\Models\PackageDeparture;
use App\Models\TravelClient;
use App\Models\TravelPayment;
use App\Models\TravelRefund;
use App\Models\User;
use App\Notifications\SmartAlert;
use App\Support\Travel\ResourceSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PackageBookingsTest extends TestCase
{
    use RefreshDatabase;

    private function seller(): User
    {
        return User::factory()->withRole(Role::TravelSalesperson)->create();
    }

    private function departure(?User $owner = null, int $capacity = 12): PackageDeparture
    {
        $owner ??= $this->seller();
        $package = Package::factory()->published()->create(['owner_id' => $owner->id, 'created_by' => $owner->id]);

        return PackageDeparture::factory()->create(['package_id' => $package->id, 'capacity' => $capacity]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function book(User $actor, PackageDeparture $departure, int $adults = 2, array $overrides = []): PackageBooking
    {
        return app(CreatePackageBooking::class)->handle($actor, array_merge([
            'package_id' => $departure->package_id,
            'package_departure_id' => $departure->id,
            'client_name' => 'Jane Doe',
            'client_phone' => '0712'.random_int(100000, 999999),
            'adults' => $adults,
        ], $overrides));
    }

    public function test_a_booking_is_priced_from_the_live_version_and_holds_slots(): void
    {
        $seller = $this->seller();
        $departure = $this->departure($seller);

        $booking = $this->book($seller, $departure, 2, ['children' => 1]);

        $this->assertSame(TravelBookingStatus::Pending, $booking->status);
        $this->assertSame(3, $booking->travelers);
        $this->assertEquals(120000, (float) $booking->amount_total); // 2 × 45,000 + 30,000
        $this->assertSame($departure->package->live_version_id, $booking->package_version_id);
        $this->assertSame($seller->id, $booking->salesperson_id);
        $this->assertSame(BookingPaymentStatus::Unpaid, $booking->payment_status);
        $this->assertNotNull($booking->hold_expires_at);

        $departure->refresh();
        $this->assertSame(3, $departure->reservedSlots());
        $this->assertSame(0, $departure->soldSlots());
        $this->assertSame(9, $departure->availableSlots());
    }

    public function test_overbooking_is_blocked_unless_a_manager_allows_it(): void
    {
        $seller = $this->seller();
        $departure = $this->departure($seller, 4);
        $this->book($seller, $departure, 3);

        try {
            $this->book($seller, $departure, 2);
            $this->fail('Overbooking should be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Only 1 slot is left', $e->errors()['adults'][0]);
        }

        $this->assertSame(1, PackageBooking::count());

        // A salesperson cannot turn overbooking on; a Sales Admin can.
        $input = ['starts_on' => $departure->starts_on->toDateString(), 'ends_on' => $departure->ends_on->toDateString(), 'capacity' => 4, 'status' => 'open', 'trip_status' => 'scheduled', 'allow_overbooking' => true];
        $this->expectsForbidden(fn () => app(SaveDeparture::class)->handle($seller, $input, $departure));

        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        app(SaveDeparture::class)->handle($admin, $input, $departure->fresh());
        $this->assertSame($admin->id, $departure->fresh()->overbooking_approved_by);

        $this->book($seller, $departure->fresh(), 2);
        $this->assertSame(2, PackageBooking::count());
    }

    public function test_closed_past_and_unsellable_departures_take_no_bookings(): void
    {
        $seller = $this->seller();
        $closed = $this->departure($seller);
        $closed->update(['status' => 'closed']);
        $this->assertValidationFails(fn () => $this->book($seller, $closed), 'package_departure_id');

        $past = $this->departure($seller);
        $past->update(['starts_on' => today()->subDay(), 'ends_on' => today()->addDay()]);
        $this->assertValidationFails(fn () => $this->book($seller, $past), 'package_departure_id');

        $draft = Package::factory()->create(['owner_id' => $seller->id, 'created_by' => $seller->id]);
        $this->assertValidationFails(fn () => app(SaveDeparture::class)->handle($seller, ['package_id' => $draft->id, 'starts_on' => today()->addWeek()->toDateString(), 'ends_on' => today()->addWeek()->toDateString(), 'capacity' => 10, 'status' => 'open', 'trip_status' => 'scheduled']), 'package_id');
    }

    public function test_capacity_cannot_drop_below_slots_taken_and_others_cannot_edit(): void
    {
        $seller = $this->seller();
        $departure = $this->departure($seller);
        $this->book($seller, $departure, 5);
        $input = ['starts_on' => $departure->starts_on->toDateString(), 'ends_on' => $departure->ends_on->toDateString(), 'capacity' => 4, 'status' => 'open', 'trip_status' => 'scheduled'];

        $this->assertValidationFails(fn () => app(SaveDeparture::class)->handle($seller, $input, $departure), 'capacity');
        $this->expectsForbidden(fn () => app(SaveDeparture::class)->handle($this->seller(), ['capacity' => 20] + $input, $departure));
    }

    public function test_clients_are_reused_by_phone_or_email(): void
    {
        $seller = $this->seller();
        $departure = $this->departure($seller);
        $this->book($seller, $departure, 1, ['client_name' => 'Mary W', 'client_phone' => '+254 712 345 678']);
        $this->book($seller, $departure, 1, ['client_name' => 'Mary Wambui', 'client_phone' => '0712345678']);
        $this->book($seller, $departure, 1, ['client_name' => 'Mary', 'client_phone' => null, 'client_email' => 'MARY@example.com']);
        $this->book($seller, $departure, 1, ['client_name' => 'Mary', 'client_phone' => '0799999999', 'client_email' => 'mary@example.com']);

        $this->assertSame(2, TravelClient::count());
    }

    public function test_influencer_codes_must_be_valid_for_packages(): void
    {
        $seller = $this->seller();
        $departure = $this->departure($seller);
        $code = InfluencerCode::factory()->create(['influencer_id' => Influencer::factory()->create(['owner_id' => $seller->id])->id, 'code' => 'SAFARI10']);

        $booking = $this->book($seller, $departure, 2, ['influencer_code' => 'safari10']);
        $this->assertSame($code->id, $booking->influencer_code_id);

        $this->assertValidationFails(fn () => $this->book($seller, $departure, 1, ['influencer_code' => 'NOPE']), 'influencer_code');
        $code->update(['applies_to' => 'flights']);
        $this->assertValidationFails(fn () => $this->book($seller, $departure, 1, ['influencer_code' => 'SAFARI10']), 'influencer_code');
    }

    public function test_only_managers_override_price_or_book_for_others(): void
    {
        $seller = $this->seller();
        $departure = $this->departure($seller);

        $this->expectsForbidden(fn () => $this->book($seller, $departure, 2, ['amount_override' => 1000, 'override_reason' => 'Friend']));

        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $booking = $this->book($admin, $departure, 2, ['amount_override' => 80000, 'override_reason' => 'Group discount', 'salesperson_id' => $seller->id]);
        $this->assertEquals(80000, (float) $booking->amount_total);
        $this->assertSame($seller->id, $booking->salesperson_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'booking.price_overridden', 'subject_id' => $booking->id]);
    }

    public function test_confirming_moves_slots_from_reserved_to_sold_and_expired_holds_release_slots(): void
    {
        $seller = $this->seller();
        $departure = $this->departure($seller);
        $kept = $this->book($seller, $departure, 2);
        $lapsed = $this->book($seller, $departure, 3);

        app(ChangeBookingStatus::class)->confirm($seller, $kept);
        $lapsed->forceFill(['hold_expires_at' => now()->subMinute()])->save();

        $departure->refresh();
        $this->assertSame(2, $departure->soldSlots());
        $this->assertSame(0, $departure->reservedSlots());

        $this->assertSame(1, app(ExpireBookingHolds::class)->handle());
        $this->assertSame(TravelBookingStatus::Cancelled, $lapsed->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'booking.hold_expired', 'subject_id' => $lapsed->id]);
        $this->artisan('travel:expire-holds')->assertSuccessful();
    }

    public function test_salespeople_only_see_their_own_bookings_and_hr_and_sales_managers_none(): void
    {
        $seller = $this->seller();
        $other = $this->seller();
        $mine = $this->book($seller, $this->departure($seller));
        $theirs = $this->book($other, $this->departure($other));

        $this->actingAs($seller)->get(route('travel.bookings.show', $mine))->assertOk();
        $this->actingAs($seller)->get(route('travel.bookings.show', $theirs))->assertNotFound();
        $this->actingAs($seller)->get(route('travel.bookings.index'))->assertOk()->assertSee($mine->reference)->assertDontSee($theirs->reference);

        foreach ([Role::Hr, Role::SalesManager, Role::Salesperson] as $role) {
            $user = User::factory()->withRole($role)->create();
            $this->actingAs($user)->get(route('travel.bookings.index'))->assertForbidden();
            $this->actingAs($user)->get(route('travel.departures.index'))->assertForbidden();
            $this->actingAs($user)->get(route('travel.cancellations.index'))->assertForbidden();
        }

        $accounts = User::factory()->withRole(Role::Accounts)->create();
        $this->actingAs($accounts)->get(route('travel.bookings.show', $theirs))->assertOk();
        $this->actingAs($accounts)->get(route('travel.departures.index'))->assertForbidden();
    }

    public function test_client_contact_is_masked_for_viewers_without_a_link_to_the_booking(): void
    {
        $owner = $this->seller();
        $booking = $this->book($owner, $this->departure($owner), 2, ['client_phone' => '0712345678']);

        $this->assertTrue($booking->canSeeClientContact($owner));
        $this->assertFalse($booking->canSeeClientContact($this->seller()));
        $this->assertTrue($booking->canSeeClientContact(User::factory()->withRole(Role::Accounts)->create()));
        $this->assertSame('0712 *** 678', TravelClient::mask('0712345678'));
    }

    public function test_driver_clashes_are_blocked_unless_a_manager_overrides(): void
    {
        $seller = $this->seller();
        $driver = Driver::factory()->create();
        $first = $this->departure($seller);
        $second = $this->departure($seller);
        $second->update(['starts_on' => $first->starts_on, 'ends_on' => $first->ends_on]);

        app(AssignTripResources::class)->forDeparture($seller, $first, $driver->id, null);
        $this->assertCount(1, ResourceSchedule::conflictsFor($driver, $first->starts_on->toDateString(), $first->ends_on->toDateString()));

        $this->assertValidationFails(fn () => app(AssignTripResources::class)->forDeparture($seller, $second, $driver->id, null), 'driver_id');
        // Ticking override does nothing for a salesperson.
        $this->assertValidationFails(fn () => app(AssignTripResources::class)->forDeparture($seller, $second, $driver->id, null, true), 'driver_id');

        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        app(AssignTripResources::class)->forDeparture($admin, $second->fresh(), $driver->id, null, true);
        $this->assertSame($driver->id, $second->fresh()->driver_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'resource.conflict_overridden']);
    }

    public function test_package_level_driver_counts_for_clashes_and_most_specific_assignment_wins(): void
    {
        $seller = $this->seller();
        $packageDriver = Driver::factory()->create();
        $bookingDriver = Driver::factory()->create();
        $departure = $this->departure($seller);
        $departure->package->update(['driver_id' => $packageDriver->id]);
        $booking = $this->book($seller, $departure->fresh());

        $this->assertSame($packageDriver->id, $booking->fresh()->effectiveDriver()->id);
        $this->assertSame('package', $booking->fresh()->driverSource());
        $this->assertCount(1, ResourceSchedule::conflictsFor($packageDriver, $departure->starts_on->toDateString(), $departure->ends_on->toDateString()));

        app(AssignTripResources::class)->forBooking($seller, $booking, $bookingDriver->id, null);
        $this->assertSame($bookingDriver->id, $booking->fresh()->effectiveDriver()->id);
        $this->assertSame('booking', $booking->fresh()->driverSource());
    }

    public function test_cancellation_approval_frees_slots_and_creates_a_refund_for_accounts(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $departure = $this->departure($seller);
        $booking = $this->book($seller, $departure, 2);
        app(ChangeBookingStatus::class)->confirm($seller, $booking);
        TravelPayment::factory()->create(['package_booking_id' => $booking->id, 'amount' => 20000]);
        app(RefreshBookingPayment::class)->handle($booking->fresh());

        $cancellation = app(RequestCancellation::class)->handle($seller, $booking->fresh(), 'Client is ill', 15000);
        $this->assertSame(CancellationStatus::Pending, $cancellation->status);

        // A salesperson can't decide, and refunds can't exceed what was paid.
        $this->expectsForbidden(fn () => app(DecideCancellation::class)->handle($seller, $cancellation, true));
        $this->assertValidationFails(fn () => app(RequestCancellation::class)->handle($seller, $booking->fresh(), 'Again', 1), 'reason');

        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $accounts = User::factory()->withRole(Role::Accounts)->create();
        app(DecideCancellation::class)->handle($admin, $cancellation, true);

        $booking->refresh();
        $this->assertSame(TravelBookingStatus::Cancelled, $booking->status);
        $this->assertSame(0, $departure->fresh()->soldSlots());
        $refund = TravelRefund::sole();
        $this->assertSame(RefundStatus::Approved, $refund->status);

        // Only Accounts pays out; completion needs a reference and updates the booking.
        $this->expectsForbidden(fn () => app(ManageRefund::class)->process($seller, $refund, 'completed', 'mpesa', 'QWE123'));
        $this->expectsForbidden(fn () => app(ManageRefund::class)->process($admin, $refund, 'completed', 'mpesa', 'QWE123'));
        $this->assertValidationFails(fn () => app(ManageRefund::class)->process($accounts, $refund, 'completed', 'mpesa', ''), 'reference');
        app(ManageRefund::class)->process($accounts, $refund, 'completed', 'mpesa', 'QWE123');

        $booking->refresh();
        $this->assertEquals(15000, (float) $booking->amount_refunded);
        $this->assertSame(BookingPaymentStatus::PartiallyRefunded, $booking->payment_status);
        $this->assertSame(CancellationStatus::Completed, $cancellation->fresh()->status);
        Notification::assertSentTo($accounts, SmartAlert::class);
    }

    public function test_nobody_but_a_super_admin_decides_their_own_refund(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $seller = $this->seller();
        $booking = $this->book($seller, $this->departure($seller));
        app(ChangeBookingStatus::class)->confirm($seller, $booking);
        TravelPayment::factory()->create(['package_booking_id' => $booking->id, 'amount' => 10000]);
        app(RefreshBookingPayment::class)->handle($booking->fresh());

        $refund = app(ManageRefund::class)->request($admin, $booking->fresh(), 4000, 'Goodwill');
        $this->expectsForbidden(fn () => app(ManageRefund::class)->decide($admin, $refund, true));
        $this->expectsForbidden(fn () => app(ManageRefund::class)->decide($seller, $refund, true));

        $super = User::factory()->withRole(Role::SuperAdmin)->create();
        app(ManageRefund::class)->decide($super, $refund, true);
        $this->assertSame(RefundStatus::Approved, $refund->fresh()->status);
    }

    public function test_pretrip_checklist_and_reminders(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $departure = $this->departure($seller);
        $departure->update(['starts_on' => today()->addDays(2), 'ends_on' => today()->addDays(4)]);
        $booking = $this->book($seller, $departure->fresh());
        app(ChangeBookingStatus::class)->confirm($seller, $booking);

        Livewire::actingAs($seller)->test(Show::class, ['booking' => $booking->id])
            ->assertSee('Pre-trip action required')
            ->call('toggleItem', 'customer_contacted')
            ->call('toggleItem', 'driver_assigned')
            ->assertHasErrors('item');

        $this->assertDatabaseHas('booking_checklist_items', ['package_booking_id' => $booking->id, 'item' => 'customer_contacted', 'completed_by' => $seller->id]);

        $this->assertSame(1, app(SendPreTripReminders::class)->handle());
        $this->assertSame(0, app(SendPreTripReminders::class)->handle(), 'At most one reminder a day.');
        Notification::assertSentTo($seller, SmartAlert::class, fn (SmartAlert $alert) => $alert->type === 'trip_missing_driver');
    }

    public function test_departure_nearly_full_and_full_alerts_go_out_once(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $departure = $this->departure($seller, 10);

        $this->book($seller, $departure, 4);
        $this->book($seller, $departure->fresh(), 4);
        $this->book($seller, $departure->fresh(), 1);
        Notification::assertSentToTimes($seller, SmartAlert::class, 1);

        $this->book($seller, $departure->fresh(), 1);
        Notification::assertSentTo($seller, SmartAlert::class, fn (SmartAlert $alert) => $alert->type === 'departure_full');
        Notification::assertSentToTimes($seller, SmartAlert::class, 2);
    }

    public function test_pages_render_and_livewire_booking_flow_works(): void
    {
        $seller = $this->seller();
        $departure = $this->departure($seller);

        $this->actingAs($seller)->get(route('travel.departures.index'))->assertOk()->assertSee($departure->package->name);
        $this->actingAs($seller)->get(route('travel.resources.index'))->assertOk();
        $this->actingAs($seller)->get(route('travel.clients.index'))->assertOk();
        $this->actingAs($seller)->get(route('travel.cancellations.index'))->assertOk();
        $this->actingAs($seller)->get(route('travel.bookings.create'))->assertOk();

        Livewire::actingAs($seller)->test(Create::class)
            ->set('packageId', (string) $departure->package_id)
            ->set('departureId', (string) $departure->id)
            ->set('form.client_name', 'Peter Doe')
            ->set('form.client_phone', '0700111222')
            ->set('form.adults', '3')
            ->set('form.guests.1.full_name', 'Ann Doe')
            ->set('form.guests.2.full_name', 'Tom Doe')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        Livewire::actingAs($seller)->test(Inventory::class)
            ->call('viewBookings', $departure->id)
            ->assertSee('Peter Doe — 3 slots')
            ->assertSee('Total: 0 / 12 sold');
    }

    private function expectsForbidden(\Closure $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    private function assertValidationFails(\Closure $callback, string $field): void
    {
        try {
            $callback();
            $this->fail("Expected a validation error on {$field}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }
}
