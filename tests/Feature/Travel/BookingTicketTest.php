<?php

namespace Tests\Feature\Travel;

use App\Enums\Role;
use App\Enums\Travel\BookingPaymentStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Livewire\Travel\Bookings\Ticket;
use App\Mail\BookingTicketMail;
use App\Models\Driver;
use App\Models\PackageBooking;
use App\Models\User;
use App\Support\Travel\BookingTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class BookingTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_booking_gets_an_unguessable_verification_token(): void
    {
        $booking = PackageBooking::factory()->create();

        $this->assertSame(32, strlen($booking->verification_token));
        $this->assertNotSame($booking->verification_token, PackageBooking::factory()->create()->verification_token);
        $this->assertStringEndsWith('/booking/verify/'.$booking->verification_token, $booking->verificationUrl());
    }

    public function test_ticket_shows_real_booking_data_with_clean_fallbacks(): void
    {
        $booking = PackageBooking::factory()->paid()->create(['adults' => 2, 'children' => 1, 'amount_total' => 85000, 'amount_paid' => 85000]);
        $booking->client->update(['name' => 'John Wambua']);

        $this->actingAs($booking->salesperson)->get(route('travel.bookings.ticket', $booking))
            ->assertOk()
            ->assertSee('Booking confirmed')
            ->assertSee($booking->reference)
            ->assertSee($booking->package->name)
            ->assertSee('John Wambua')
            ->assertSee('2 adults, 1 child')
            ->assertSee('To be assigned')
            ->assertSee('Not required')
            ->assertSee('KES 85,000')
            ->assertSee('Paid in full')
            ->assertSee('data:image/png;base64,', false)
            ->assertDontSee('>null<', false)
            ->assertDontSee('>undefined<', false);
    }

    public function test_driver_on_the_departure_shows_on_the_ticket(): void
    {
        $booking = PackageBooking::factory()->confirmed()->create();
        $booking->departure->update(['driver_id' => Driver::factory()->create(['name' => 'David Kamau'])->id]);

        $this->assertSame('David Kamau', BookingTicket::for($booking->fresh())->driver()['name']);
    }

    public function test_partial_payment_shows_the_balance_due(): void
    {
        $booking = PackageBooking::factory()->confirmed()->create([
            'amount_total' => 90000, 'amount_paid' => 30000, 'payment_status' => BookingPaymentStatus::PartiallyPaid,
        ]);

        $this->assertSame('Balance due: KES 60,000', BookingTicket::for($booking)->payment()['label']);
    }

    public function test_pdf_downloads_as_one_page_ticket(): void
    {
        $booking = PackageBooking::factory()->paid()->create();

        $response = $this->actingAs($booking->salesperson)->get(route('travel.bookings.ticket.pdf', $booking));

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page[^s]/', $response->getContent()));
    }

    public function test_verification_page_shows_live_status_and_nothing_private(): void
    {
        $booking = PackageBooking::factory()->paid()->create(['notes' => 'Internal: VIP, give upgrade']);
        $booking->client->update(['name' => 'Private Person', 'phone' => '0712345678']);

        $this->get(route('bookings.verify', $booking->verification_token))
            ->assertOk()
            ->assertSee('Booking verified')
            ->assertSee($booking->reference)
            ->assertDontSee('Private Person')
            ->assertDontSee('0712345678')
            ->assertDontSee('Internal: VIP')
            ->assertDontSee('KES');

        $booking->update(['status' => TravelBookingStatus::Cancelled, 'cancelled_at' => now()]);

        $this->get(route('bookings.verify', $booking->verification_token))
            ->assertOk()
            ->assertDontSee('Booking verified')
            ->assertSee('Booking cancelled')
            ->assertSee('not valid for travel');
    }

    public function test_bookings_cannot_be_found_by_id_or_reference(): void
    {
        $booking = PackageBooking::factory()->paid()->create();

        $this->get('/booking/verify/'.$booking->id)->assertNotFound();
        $this->get('/booking/verify/'.$booking->reference)->assertNotFound();
        $this->get('/booking/verify/'.str_repeat('a', 32))->assertOk()->assertSee('Booking not found');
    }

    public function test_only_people_who_can_see_the_booking_open_its_ticket(): void
    {
        $booking = PackageBooking::factory()->paid()->create();

        $this->actingAs(User::factory()->withRole(Role::TravelSalesperson)->create())->get(route('travel.bookings.ticket', $booking))->assertNotFound();
        $this->actingAs(User::factory()->withRole(Role::TravelSalesperson)->create())->get(route('travel.bookings.ticket.pdf', $booking))->assertNotFound();
        $this->actingAs(User::factory()->withRole(Role::SalesManager)->create())->get(route('travel.bookings.ticket', $booking))->assertForbidden();
        $this->actingAs(User::factory()->withRole(Role::Hr)->create())->get(route('travel.bookings.ticket.pdf', $booking))->assertForbidden();
        $this->actingAs(User::factory()->withRole(Role::SalesAdmin)->create())->get(route('travel.bookings.ticket', $booking))->assertOk();
    }

    public function test_confirmed_ticket_is_emailed_with_the_pdf(): void
    {
        Mail::fake();
        $booking = PackageBooking::factory()->paid()->create();

        Livewire::actingAs($booking->salesperson)->test(Ticket::class, ['booking' => $booking->id])
            ->call('sendToClient')
            ->assertDispatched('toast');

        Mail::assertQueued(BookingTicketMail::class, fn (BookingTicketMail $mail) => $mail->hasTo($booking->client->email));
        $this->assertCount(1, (new BookingTicketMail($booking))->attachments());
    }

    public function test_pending_bookings_are_never_emailed_as_tickets(): void
    {
        Mail::fake();
        $booking = PackageBooking::factory()->create();

        Livewire::actingAs($booking->salesperson)->test(Ticket::class, ['booking' => $booking->id])
            ->assertSee('Preview only')
            ->call('sendToClient');

        Mail::assertNothingQueued();
    }
}
