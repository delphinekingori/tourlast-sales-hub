<?php

namespace App\Enums\Travel;

/**
 * Monthly Travel Sales targets, set by a Sales Admin.
 */
enum TravelTargetMetric: string
{
    case FlightBookings = 'flight_bookings';
    case FlightRevenue = 'flight_revenue';
    case TourBookings = 'tour_bookings';
    case TourRevenue = 'tour_revenue';

    public function label(): string
    {
        return match ($this) {
            self::FlightBookings => 'Flight bookings',
            self::FlightRevenue => 'Flight revenue (KES)',
            self::TourBookings => 'Tour & experience bookings',
            self::TourRevenue => 'Tour & experience revenue (KES)',
        };
    }

    public function isMoney(): bool
    {
        return in_array($this, [self::FlightRevenue, self::TourRevenue], true);
    }
}
