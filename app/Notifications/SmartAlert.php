<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * An automatic in-app alert about partners, deals, follow-ups or contracts.
 */
class SmartAlert extends Notification
{
    use Queueable;

    /**
     * Alert kinds with their label, icon and pill tone.
     *
     * @var array<string, array{label: string, icon: string, tone: string}>
     */
    public const Types = [
        'property_referred' => ['label' => 'New property referred', 'icon' => 'building', 'tone' => 'brand'],
        'onboarding_submitted' => ['label' => 'New onboarding submitted', 'icon' => 'clipboard', 'tone' => 'neutral'],
        'partner_approved' => ['label' => 'Partner approved', 'icon' => 'check-circle', 'tone' => 'success'],
        'partner_rejected' => ['label' => 'Partner rejected', 'icon' => 'ban', 'tone' => 'danger'],
        'deal_won' => ['label' => 'Deal won', 'icon' => 'target', 'tone' => 'success'],
        'deal_lost' => ['label' => 'Deal lost', 'icon' => 'x', 'tone' => 'danger'],
        'contract_expiring' => ['label' => 'Contract expiring', 'icon' => 'clock', 'tone' => 'warning'],
        'follow_up_overdue' => ['label' => 'Follow-up overdue', 'icon' => 'alert', 'tone' => 'warning'],
        'property_inactive' => ['label' => 'Property inactive', 'icon' => 'alert', 'tone' => 'danger'],
        'first_booking' => ['label' => 'First booking received', 'icon' => 'chart', 'tone' => 'success'],
        'payment_details_changed' => ['label' => 'Payment details changed', 'icon' => 'lock', 'tone' => 'warning'],
        'duplicate_property' => ['label' => 'Possible duplicate property', 'icon' => 'alert', 'tone' => 'warning'],
        'ownership_transferred' => ['label' => 'Ownership transferred', 'icon' => 'user', 'tone' => 'brand'],
        'reengage_due' => ['label' => 'Re-engagement due', 'icon' => 'clock', 'tone' => 'brand'],
        'account_status' => ['label' => 'Account suspended or fired', 'icon' => 'lock', 'tone' => 'danger'],
    ];

    public function __construct(
        public string $type,
        public string $title,
        public string $body,
        public ?string $url = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
        ];
    }
}
