<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * An automatic in-app alert about partners, deals, follow-ups or contracts.
 */
class SmartAlert extends Notification implements ShouldQueue
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
        'property_deleted' => ['label' => 'Property deleted', 'icon' => 'x', 'tone' => 'danger'],
        'property_restored' => ['label' => 'Property restored', 'icon' => 'check-circle', 'tone' => 'success'],
        'first_booking' => ['label' => 'First booking received', 'icon' => 'chart', 'tone' => 'success'],
        'payment_details_changed' => ['label' => 'Payment details changed', 'icon' => 'lock', 'tone' => 'warning'],
        'duplicate_property' => ['label' => 'Possible duplicate property', 'icon' => 'alert', 'tone' => 'warning'],
        'ownership_transferred' => ['label' => 'Ownership transferred', 'icon' => 'user', 'tone' => 'brand'],
        'reengage_due' => ['label' => 'Re-engagement due', 'icon' => 'clock', 'tone' => 'brand'],
        'account_status' => ['label' => 'Account suspended or fired', 'icon' => 'lock', 'tone' => 'danger'],

        // Travel Sales
        'package_submitted' => ['label' => 'Package submitted for approval', 'icon' => 'shield', 'tone' => 'brand'],
        'package_approved' => ['label' => 'Package approved', 'icon' => 'check-circle', 'tone' => 'success'],
        'package_rejected' => ['label' => 'Package rejected', 'icon' => 'ban', 'tone' => 'danger'],
        'package_changes_requested' => ['label' => 'Package changes requested', 'icon' => 'alert', 'tone' => 'warning'],
        'package_published' => ['label' => 'Package published', 'icon' => 'map', 'tone' => 'success'],
        'departure_nearly_full' => ['label' => 'Departure nearly full', 'icon' => 'cube', 'tone' => 'warning'],
        'departure_full' => ['label' => 'Departure fully booked', 'icon' => 'cube', 'tone' => 'danger'],
        'travel_booking_new' => ['label' => 'New package booking', 'icon' => 'ticket', 'tone' => 'brand'],
        'travel_booking_cancelled' => ['label' => 'Booking cancellation', 'icon' => 'ticket', 'tone' => 'danger'],
        'travel_refund' => ['label' => 'Refund', 'icon' => 'refresh', 'tone' => 'warning'],
        'travel_payment_received' => ['label' => 'Payment received', 'icon' => 'wallet', 'tone' => 'success'],
        'payment_unmatched' => ['label' => 'Unmatched M-Pesa payment', 'icon' => 'wallet', 'tone' => 'warning'],
        'trip_upcoming' => ['label' => 'Upcoming trip', 'icon' => 'calendar', 'tone' => 'brand'],
        'trip_missing_driver' => ['label' => 'Trip has no driver', 'icon' => 'truck', 'tone' => 'warning'],
        'trip_missing_guide' => ['label' => 'Trip has no guide', 'icon' => 'truck', 'tone' => 'warning'],
        'pretrip_action' => ['label' => 'Pre-trip action required', 'icon' => 'clipboard', 'tone' => 'warning'],
        'flight_sync_failed' => ['label' => 'Flight data delayed', 'icon' => 'plane', 'tone' => 'danger'],
        'influencer_commission' => ['label' => 'Influencer commission', 'icon' => 'megaphone', 'tone' => 'brand'],
    ];

    public function __construct(
        public string $type,
        public string $title,
        public string $body,
        public ?string $url = null,
    ) {
        // Sent from inside the sync transaction: wait for the commit, and keep a
        // broadcast outage from rolling back the record that raised the alert.
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * Pushed to the person's private channel so their bell updates at once.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
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
