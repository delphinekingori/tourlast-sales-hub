<?php

namespace App\Support\Travel;

use App\Enums\Travel\DepartureStatus;
use App\Models\PackageDeparture;
use App\Support\Alerts;

/**
 * "Nearly full" and "Full" alerts for a departure, each sent once. When
 * slots free up again the departure can alert again.
 */
class DepartureAlerts
{
    public static function check(PackageDeparture $departure): void
    {
        $departure->refresh()->loadMissing('package');
        $status = $departure->availabilityStatus();
        $last = $departure->last_availability_alert;
        $label = $departure->package->name.' ('.$departure->dateLabel().')';
        $sold = $departure->soldSlots() + $departure->reservedSlots();
        $url = route('travel.departures.index', ['package' => $departure->package_id]);

        $alert = match (true) {
            $status === DepartureStatus::Full && $last !== DepartureStatus::Full->value => DepartureStatus::Full,
            $status === DepartureStatus::NearlyFull && $last === null => DepartureStatus::NearlyFull,
            default => null,
        };

        if ($alert === DepartureStatus::Full) {
            Alerts::sendTravel('departure_full', 'Departure fully booked', "{$label} is fully booked: {$sold} / {$departure->capacity} slots taken.", $url, $departure->package->owner);
        } elseif ($alert === DepartureStatus::NearlyFull) {
            $left = max(0, $departure->capacity - $sold);
            Alerts::sendTravel('departure_nearly_full', 'Departure nearly full', "{$label}: {$sold} / {$departure->capacity} sold, {$left} ".($left === 1 ? 'slot' : 'slots').' remaining.', $url, $departure->package->owner);
        }

        $next = match ($status) {
            DepartureStatus::Full => DepartureStatus::Full->value,
            DepartureStatus::NearlyFull => $last === DepartureStatus::Full->value ? DepartureStatus::NearlyFull->value : ($alert ? DepartureStatus::NearlyFull->value : $last),
            default => null,
        };

        if ($next !== $last) {
            $departure->forceFill(['last_availability_alert' => $next])->saveQuietly();
        }
    }
}
