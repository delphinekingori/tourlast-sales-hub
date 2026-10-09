<?php

namespace App\Livewire\Travel\Bookings;

use App\Actions\Travel\Bookings\SendBookingTicket;
use App\Models\PackageBooking;
use App\Support\Travel\BookingTicket;
use App\Support\Travel\TravelAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Preview of the confirmation ticket exactly as the client receives it, with
 * download, print, share and send actions. Rendered live from the booking.
 */
#[Title('Booking ticket')]
class Ticket extends Component
{
    #[Locked]
    public int $bookingId;

    public function mount(int|string $booking): void
    {
        $user = Auth::user();
        abort_unless(TravelAccess::works($user) || TravelAccess::handlesPayments($user), 403);

        $this->bookingId = PackageBooking::query()->visibleTo($user)->findOrFail($booking)->id;
    }

    public function sendToClient(SendBookingTicket $send): void
    {
        try {
            $send->handle(Auth::user(), $this->booking());
        } catch (ValidationException $exception) {
            $this->dispatch('toast', message: $exception->validator->errors()->first(), tone: 'danger');

            return;
        }

        $this->dispatch('toast', message: 'Ticket emailed to '.$this->booking()->client->email.'.');
    }

    public function render(): View
    {
        $booking = $this->booking();
        $ticket = BookingTicket::for($booking);
        $phone = preg_replace('/\D/', '', (string) $booking->client?->phone);
        $phone = str_starts_with((string) $phone, '0') ? '254'.substr((string) $phone, 1) : $phone;
        $message = "Your Tourlast booking {$ticket->reference()} ({$ticket->packageName()}, {$ticket->dateLabel()}). Check its status any time: {$ticket->verificationUrl()}";

        return view('livewire.travel.bookings.ticket', [
            'booking' => $booking,
            'ticket' => $ticket,
            'canSend' => $booking->isWorkableBy(Auth::user()) && $ticket->isIssued(),
            'whatsappUrl' => $ticket->isIssued() ? 'https://wa.me/'.($phone ?: '').'?text='.rawurlencode($message) : null,
            'shareText' => $message,
        ]);
    }

    private function booking(): PackageBooking
    {
        return PackageBooking::query()->visibleTo(Auth::user())->findOrFail($this->bookingId);
    }
}
