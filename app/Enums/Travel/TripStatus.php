<?php

namespace App\Enums\Travel;

/**
 * How the trip itself is going (separate from the package and the bookings).
 */
enum TripStatus: string
{
    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case Preparing = 'preparing';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Confirmed => 'Confirmed',
            self::Preparing => 'Preparing',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Scheduled => 'neutral',
            self::Confirmed, self::InProgress => 'brand',
            self::Preparing => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(
            array_column(self::cases(), 'value'),
            array_map(fn (self $case): string => $case->label(), self::cases()),
        );
    }
}
