<?php

namespace Tests\Feature\Travel\Bookings;

use App\Actions\Travel\Bookings\CreatePackageBooking;
use App\Actions\Travel\Bookings\SaveBookingGuests;
use App\Enums\Role;
use App\Enums\Travel\GuestType;
use App\Livewire\Travel\Bookings\Create;
use App\Livewire\Travel\Bookings\Show;
use App\Models\AuditEvent;
use App\Models\Package;
use App\Models\PackageBooking;
use App\Models\PackageBookingGuest;
use App\Models\PackageDeparture;
use App\Models\User;
use App\Support\Travel\BookingTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BookingGuestsTest extends TestCase
{
    use RefreshDatabase;

    private function seller(): User
    {
        return User::factory()->withRole(Role::TravelSalesperson)->create();
    }

    private function departure(User $owner): PackageDeparture
    {
        $package = Package::factory()->published()->create(['owner_id' => $owner->id, 'created_by' => $owner->id]);

        return PackageDeparture::factory()->create(['package_id' => $package->id, 'capacity' => 12]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function book(User $actor, PackageDeparture $departure, array $overrides = []): PackageBooking
    {
        return app(CreatePackageBooking::class)->handle($actor, array_merge([
            'package_id' => $departure->package_id,
            'package_departure_id' => $departure->id,
            'client_name' => 'Jane Doe',
            'client_phone' => '0712345678',
            'adults' => 2,
            'children' => 1,
        ], $overrides));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function guests(): array
    {
        return [
            ['full_name' => 'Jane Doe', 'type' => 'adult', 'is_booker' => true, 'nationality' => 'Kenya', 'id_number' => '12345678', 'phone' => '0712345678'],
            ['full_name' => 'John Doe', 'type' => 'adult', 'date_of_birth' => '1985-04-02', 'special_requirements' => 'Nut allergy'],
            ['full_name' => 'Amani Doe', 'type' => 'child', 'date_of_birth' => '2017-06-10', 'email' => ''],
        ];
    }

    public function test_guests_are_saved_with_the_booking_and_id_numbers_are_encrypted(): void
    {
        $seller = $this->seller();
        $booking = $this->book($seller, $this->departure($seller), ['guests' => $this->guests()]);

        $guests = $booking->guests()->get();
        $this->assertSame(['Jane Doe', 'John Doe', 'Amani Doe'], $guests->pluck('full_name')->all());
        $this->assertSame([1, 2, 3], $guests->pluck('position')->all());
        $this->assertSame(GuestType::Child, $guests[2]->type);
        $this->assertTrue($guests[0]->is_booker);
        $this->assertFalse($guests[1]->is_booker);
        $this->assertSame('Nut allergy', $guests[1]->special_requirements);
        $this->assertNull($guests[2]->email);

        $this->assertSame('12345678', $guests[0]->id_number);
        $this->assertSame('*****678', $guests[0]->maskedIdNumber());
        $raw = DB::table('package_booking_guests')->where('id', $guests[0]->id)->value('id_number');
        $this->assertNotSame('12345678', $raw);
        $this->assertStringNotContainsString('12345678', (string) $raw);
    }

    public function test_guests_must_match_the_number_and_type_of_travelers(): void
    {
        $seller = $this->seller();
        $departure = $this->departure($seller);
        $guests = $this->guests();

        $this->assertValidationFails(fn () => $this->book($seller, $departure, ['guests' => array_slice($guests, 0, 2)]), 'guests');
        $this->assertValidationFails(fn () => $this->book($seller, $departure, ['guests' => [...$guests, ['full_name' => 'Extra', 'type' => 'adult']]]), 'guests');
        $this->assertValidationFails(fn () => $this->book($seller, $departure, ['guests' => []]), 'guests');

        $wrongTypes = $guests;
        $wrongTypes[2]['type'] = 'adult';
        $this->assertValidationFails(fn () => $this->book($seller, $departure, ['guests' => $wrongTypes]), 'guests');

        $nameless = $guests;
        $nameless[1]['full_name'] = '';
        $this->assertValidationFails(fn () => $this->book($seller, $departure, ['guests' => $nameless]), 'guests.1.full_name');

        $twoBookers = $guests;
        $twoBookers[1]['is_booker'] = true;
        $this->assertValidationFails(fn () => $this->book($seller, $departure, ['guests' => $twoBookers]), 'guests');

        $this->assertSame(0, PackageBooking::count());
        $this->assertSame(0, PackageBookingGuest::count());
    }

    public function test_the_booking_form_draws_one_row_per_traveler_and_prefills_the_booker(): void
    {
        $seller = $this->seller();
        $departure = $this->departure($seller);

        $component = Livewire::actingAs($seller)->test(Create::class)
            ->set('packageId', (string) $departure->package_id)
            ->set('departureId', (string) $departure->id)
            ->assertCount('form.guests', 2)
            ->set('form.client_name', 'Peter Doe')
            ->set('form.client_phone', '0700111222')
            ->assertSet('form.guests.0.full_name', 'Peter Doe')
            ->assertSet('form.guests.0.phone', '0700111222')
            ->assertSet('form.guests.0.is_booker', true)
            ->set('form.adults', '3')
            ->set('form.children', '1')
            ->assertCount('form.guests', 4)
            ->assertSet('form.guests.0.full_name', 'Peter Doe')
            ->assertSet('form.guests.3.type', 'child');

        $component->call('save')->assertHasErrors(['form.guests.1.full_name', 'form.guests.2.full_name', 'form.guests.3.full_name']);
        $this->assertSame(0, PackageBooking::count());

        $component
            ->set('form.guests.1.full_name', 'Mary Doe')
            ->set('form.guests.2.full_name', 'Paul Doe')
            ->set('form.guests.3.full_name', 'Baby Doe')
            ->set('form.children', '0')
            ->assertCount('form.guests', 3)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $booking = PackageBooking::query()->sole();
        $this->assertSame(['Peter Doe', 'Mary Doe', 'Paul Doe'], $booking->guests->pluck('full_name')->all());
        $this->assertTrue($booking->guests[0]->is_booker);

        Livewire::actingAs($seller)->test(Create::class)
            ->set('bookerTravelling', false)
            ->set('form.client_name', 'Agent Booker')
            ->assertSet('form.guests.0.full_name', '')
            ->assertSet('form.guests.0.is_booker', false);
    }

    public function test_guest_details_can_be_added_and_edited_later_with_an_audit_trail(): void
    {
        $seller = $this->seller();
        $booking = $this->book($seller, $this->departure($seller), ['children' => 0]);

        $this->actingAs($seller)->get(route('travel.bookings.show', $booking))
            ->assertOk()
            ->assertSee('Guest details not captured')
            ->assertSee('Add guest details');

        Livewire::actingAs($seller)->test(Show::class, ['booking' => $booking->id])
            ->call('openGuests')
            ->assertSet('showGuests', true)
            ->assertCount('guests', 2)
            ->set('guests.0.full_name', 'Jane Doe')
            ->set('guests.0.id_number', 'A1234567')
            ->call('saveGuests')
            ->assertHasErrors(['guests.1.full_name'])
            ->set('guests.1.full_name', 'John Doe')
            ->call('saveGuests')
            ->assertHasNoErrors()
            ->assertSet('showGuests', false)
            ->assertSee('Jane Doe')
            ->assertSee('ID *****567')
            ->assertDontSee('A1234567')
            ->assertDontSee('Guest details not captured');

        $added = AuditEvent::query()->where('action', 'booking.guests_added')->sole();
        $this->assertSame($booking->id, $added->subject_id);
        $this->assertStringContainsString('Jane Doe, John Doe', $added->summary);

        Livewire::actingAs($seller)->test(Show::class, ['booking' => $booking->id])
            ->call('openGuests')
            ->assertSet('guests.0.id_number', 'A1234567')
            ->set('guests.1.full_name', 'Johnathan Doe')
            ->call('saveGuests')
            ->assertHasNoErrors();

        $updated = AuditEvent::query()->where('action', 'booking.guests_updated')->sole();
        $this->assertSame(['Jane Doe, John Doe', 'Jane Doe, Johnathan Doe'], $updated->changes['guests']);
        $this->assertStringNotContainsString('A1234567', json_encode($updated->toArray()));
        $this->assertSame(['Jane Doe', 'Johnathan Doe'], $booking->guests()->pluck('full_name')->all());
    }

    public function test_only_people_who_work_the_booking_can_change_guests(): void
    {
        $owner = $this->seller();
        $booking = $this->book($owner, $this->departure($owner), ['children' => 0, 'guests' => [
            ['full_name' => 'Jane Doe', 'type' => 'adult'],
            ['full_name' => 'John Doe', 'type' => 'adult'],
        ]]);
        $rows = [['full_name' => 'Hijack', 'type' => 'adult'], ['full_name' => 'Hijack Two', 'type' => 'adult']];

        $this->expectsForbidden(fn () => app(SaveBookingGuests::class)->handle($this->seller(), $booking, $rows));

        $accounts = User::factory()->withRole(Role::Accounts)->create();
        $this->actingAs($accounts)->get(route('travel.bookings.show', $booking))->assertOk()->assertSee('John Doe')->assertDontSee('Edit guests');
        $this->expectsForbidden(fn () => app(SaveBookingGuests::class)->handle($accounts, $booking, $rows));
        Livewire::actingAs($accounts)->test(Show::class, ['booking' => $booking->id])->call('openGuests')->assertForbidden();

        $this->actingAs(User::factory()->withRole(Role::SalesManager)->create())->get(route('travel.bookings.show', $booking))->assertForbidden();
        $this->actingAs($this->seller())->get(route('travel.bookings.show', $booking))->assertNotFound();

        $this->assertSame(['Jane Doe', 'John Doe'], $booking->guests()->pluck('full_name')->all());
        $this->assertSame(0, AuditEvent::query()->whereIn('action', ['booking.guests_added', 'booking.guests_updated'])->count());

        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        app(SaveBookingGuests::class)->handle($admin, $booking, $rows);
        $this->assertSame(['Hijack', 'Hijack Two'], $booking->guests()->pluck('full_name')->all());
    }

    public function test_pdf_ticket_lists_guest_names_and_stays_one_page(): void
    {
        $booking = PackageBooking::factory()->paid()->create(['adults' => 3]);
        foreach (['Jane Wanjiru Doe', 'John Kamau Doe', 'Amani Otieno Doe'] as $i => $name) {
            PackageBookingGuest::factory()->create(['package_booking_id' => $booking->id, 'position' => $i + 1, 'full_name' => $name]);
        }

        $this->assertSame('Jane Wanjiru Doe, John Kamau Doe, Amani Otieno Doe', BookingTicket::for($booking->fresh())->guestNames());
        $this->assertNull(BookingTicket::for(PackageBooking::factory()->paid()->create())->guestNames());

        $response = $this->actingAs($booking->salesperson)->get(route('travel.bookings.ticket.pdf', $booking));
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page[^s]/', $response->getContent()));
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
