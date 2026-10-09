<?php

namespace App\Http\Controllers\Travel;

use App\Http\Controllers\Controller;
use App\Models\PackageBooking;
use App\Support\Travel\BookingTicket;
use App\Support\Travel\BookingTicketPdf;
use App\Support\Travel\TravelAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class BookingTicketController extends Controller
{
    /**
     * Download the ticket PDF (staff who may see the booking).
     */
    public function pdf(Request $request, int $booking): Response
    {
        $user = $request->user();
        abort_unless(TravelAccess::works($user) || TravelAccess::handlesPayments($user), 403);

        $ticket = BookingTicket::for(PackageBooking::query()->visibleTo($user)->findOrFail($booking));

        return BookingTicketPdf::make($ticket)->download($ticket->fileName());
    }

    /**
     * Public verification page behind the ticket QR code. Looked up by an
     * unguessable token (never the id or reference), always shows the live
     * status, and shows nothing private.
     */
    public function verify(string $token): View
    {
        $booking = PackageBooking::query()->where('verification_token', $token)->first();

        return view('travel.ticket.verify', [
            'ticket' => $booking ? BookingTicket::for($booking) : null,
        ]);
    }
}
