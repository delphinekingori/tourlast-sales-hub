<?php

namespace App\Actions\Travel\Bookings;

use App\Mail\BookingTicketMail;
use App\Models\PackageBooking;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\BookingTicket;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Emails the confirmation ticket (PDF attached) to the booking's client.
 * Only confirmed or completed bookings, by someone who works the booking.
 */
class SendBookingTicket
{
    public function handle(User $actor, PackageBooking $booking): void
    {
        abort_unless($booking->isWorkableBy($actor), 403);

        if (! BookingTicket::for($booking)->isIssued()) {
            throw ValidationException::withMessages(['ticket' => 'Only confirmed bookings get a ticket. Confirm the booking first.']);
        }

        $email = $booking->client?->email;

        if (blank($email)) {
            throw ValidationException::withMessages(['ticket' => 'The client has no email address. Add one, or share the ticket another way.']);
        }

        Mail::to($email, $booking->client->name)->send(new BookingTicketMail($booking));

        Audit::record($booking, 'booking.ticket_sent', "Ticket {$booking->reference} emailed to the client");
    }
}
