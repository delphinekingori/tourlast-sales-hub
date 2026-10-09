<x-mail::message>
# Your booking is confirmed

Hello {{ $ticket->leadGuest() }},

Thank you for booking with Tourlast. Your confirmation ticket is attached.

<x-mail::table>
| | |
|:--|:--|
| **Booking reference** | {{ $ticket->reference() }} |
| **Package** | {{ $ticket->packageName() }} |
| **Date** | {{ $ticket->dateLabel() }} |
| **Pick-up** | {{ $ticket->pickupTime() }} · {{ $ticket->pickupLocation() }} |
| **Guests** | {{ $ticket->guests() }} |
| **Payment** | {{ $ticket->payment()['label'] }} |
</x-mail::table>

<x-mail::button :url="$verifyUrl">
View booking status
</x-mail::button>

Please present the ticket (printed or on your phone) when required. The QR code always shows the booking's current status.

Questions? Reply to this email or write to {{ \App\Support\Travel\BookingTicket::SupportEmail }}.

The Tourlast team
</x-mail::message>
