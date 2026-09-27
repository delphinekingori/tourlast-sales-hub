<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\User;
use App\Notifications\SmartAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Sends Smart Alerts to management (Super Admin, Sales Admin, Sales Manager),
 * plus the salesperson concerned where the alert is about their own work.
 */
class Alerts
{
    public static function send(string $type, string $title, string $body, ?string $url = null, ?User $salesperson = null): void
    {
        $recipients = self::management();

        if ($salesperson && $salesperson->is_active) {
            $recipients->push($salesperson);
        }

        $recipients = $recipients->unique('id')->values();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new SmartAlert($type, $title, $body, $url));
        }
    }

    /**
     * Alerts only for the people who handle a sensitive change (HR and Finance).
     */
    public static function sendToPaymentReviewers(string $type, string $title, string $body, ?string $url = null): void
    {
        $recipients = User::query()->active()->permission(Permission::ViewPaymentDetails->value)->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new SmartAlert($type, $title, $body, $url));
        }
    }

    /**
     * @return Collection<int, User>
     */
    public static function management(): Collection
    {
        return User::query()->active()->permission(Permission::ReceiveSmartAlerts->value)->get();
    }
}
