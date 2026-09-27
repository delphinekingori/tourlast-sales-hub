<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ManagerDailyAlert extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $alert
     */
    public function __construct(public User $manager, public array $alert) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Sales Hub daily summary · '.now()->format('j M'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.manager-daily-alert', with: [
            'performanceUrl' => route('team.performance'),
            'unattributedUrl' => route('onboardings.unattributed'),
        ]);
    }
}
