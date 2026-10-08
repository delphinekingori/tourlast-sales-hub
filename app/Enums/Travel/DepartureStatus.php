<?php

namespace App\Enums\Travel;

/**
 * The stored status of a departure. "Nearly full" and "Full" are worked out from the slots sold (see PackageDeparture::availabilityStatus).
 */
enum DepartureStatus: string
{
    case Open = 'open';
    case NearlyFull = 'nearly_full';
    case Full = 'full';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::NearlyFull => 'Nearly full',
            self::Full => 'Full',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open => 'success',
            self::NearlyFull => 'warning',
            self::Full, self::Cancelled => 'danger',
            self::Closed => 'neutral',
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
