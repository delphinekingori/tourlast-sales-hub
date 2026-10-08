<?php

namespace App\Support\Travel;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * The ticket as a one-page, ticket-sized PDF (rendered live from the booking).
 */
class BookingTicketPdf
{
    /** A compact ticket-sized page, 212 × 113 mm (in points). */
    public const Paper = [0, 0, 601, 321];

    public static function make(BookingTicket $ticket): DomPdf
    {
        return Pdf::loadView('travel.ticket.pdf', [
            'ticket' => $ticket,
            'logo' => base64_encode((string) file_get_contents(public_path('images/tourlast-logo.png'))),
            'qr' => $ticket->qrDataUri(8),
            'barcode' => $ticket->barcodeDataUri(60),
            'image' => $ticket->imageDataUri(),
        ])->setPaper(self::Paper);
    }
}
