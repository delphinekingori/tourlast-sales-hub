<?php

namespace App\Actions\Travel\Bookings;

use App\Enums\Travel\TravelBookingStatus;
use App\Models\PackageBooking;
use App\Support\Alerts;
use App\Support\Travel\PreTripChecklist;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daily reminders for confirmed bookings departing in the next three days
 * with checklist items outstanding (missing driver, missing guide, other
 * pre-trip tasks). At most one round per booking per day.
 */
class SendPreTripReminders
{
    public const DaysAhead = 3;

    public function handle(): int
    {
        $bookings = PackageBooking::query()
            ->where('status', TravelBookingStatus::Confirmed)
            ->where(fn (Builder $query) => $query->whereNull('last_pretrip_alert_on')->orWhereDate('last_pretrip_alert_on', '<', today()))
            ->whereHas('departure', fn (Builder $departure) => $departure
                ->whereDate('starts_on', '>=', today())
                ->whereDate('starts_on', '<=', today()->addDays(self::DaysAhead)))
            ->with(['client', 'salesperson', 'package.driver', 'package.guide', 'departure.driver', 'departure.guide', 'driver', 'guide', 'checklistItems'])
            ->get();

        $sent = 0;

        foreach ($bookings as $booking) {
            $outstanding = PreTripChecklist::outstanding($booking)->keyBy('key');

            if ($outstanding->isEmpty()) {
                continue;
            }

            $label = "{$booking->reference} — {$booking->package->name}, {$booking->departure->dateLabel()}";
            $url = route('travel.bookings.show', $booking);

            if ($outstanding->has('driver_assigned')) {
                Alerts::sendTravel('trip_missing_driver', 'Trip has no driver', "{$label} has no driver assigned.", $url, $booking->salesperson);
            }

            if ($outstanding->has('guide_assigned')) {
                Alerts::sendTravel('trip_missing_guide', 'Trip has no guide', "{$label} needs a guide and none is assigned.", $url, $booking->salesperson);
            }

            $other = $outstanding->except(['driver_assigned', 'guide_assigned']);

            if ($other->isNotEmpty()) {
                Alerts::sendTravel('pretrip_action', 'Pre-trip action required', "{$label}: ".$other->pluck('label')->implode(', ').'.', $url, $booking->salesperson);
            }

            $booking->forceFill(['last_pretrip_alert_on' => today()])->saveQuietly();
            $sent++;
        }

        return $sent;
    }
}
