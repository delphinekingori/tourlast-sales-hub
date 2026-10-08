<?php

namespace App\Mail;

use App\Models\PackageBooking;
use App\Support\Travel\BookingTicket;
use App\Support\Travel\BookingTicketPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The confirmation ticket to the client, with the PDF attached. The PDF is
 * rendered when the mail is sent, so it carries the latest booking details.
 */
class BookingTicketMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public PackageBooking $booking) {}

    public function envelope(): Envelope
    {
        $ticket = BookingTicket::for($this->booking);

        return new Envelope(subject: 'Your Tourlast booking '.$ticket->reference().' · '.$ticket->packageName());
    }

    public function content(): Content
    {
        $ticket = BookingTicket::for($this->booking);

        return new Content(markdown: 'mail.booking-ticket', with: [
            'ticket' => $ticket,
            'verifyUrl' => $ticket->verificationUrl(),
        ]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $ticket = BookingTicket::for($this->booking);

        return [
            Attachment::fromData(fn (): string => BookingTicketPdf::make($ticket)->output(), $ticket->fileName())->withMime('application/pdf'),
        ];
    }
}
