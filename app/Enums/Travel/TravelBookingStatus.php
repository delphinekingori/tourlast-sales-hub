<?php

namespace App\Enums\Travel;

/**
 * The status of a package booking (payment is tracked separately).
 */
enum TravelBookingStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No-show',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Confirmed => 'brand',
            self::Completed => 'success',
            self::Cancelled, self::NoShow => 'danger',
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
