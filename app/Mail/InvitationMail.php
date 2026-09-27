<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Invitation $invitation, public string $plainToken) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You've been invited to join Tourlast Sales Hub",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.invitation',
            with: [
                'acceptUrl' => route('invitations.accept', $this->plainToken),
                'inviterName' => $this->invitation->inviter->name,
                'roleLabel' => $this->invitation->role->label(),
                'expiresOn' => $this->invitation->expires_at->format('j F Y'),
            ],
        );
    }
}
