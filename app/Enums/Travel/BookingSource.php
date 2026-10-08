<?php

namespace App\Enums\Travel;

/**
 * Where a package booking was entered.
 */
enum BookingSource: string
{
    case Manual = 'manual';
    case Synced = 'synced';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Entered in the Hub',
            self::Synced => 'Synced from Tourlast',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Manual => 'neutral',
            self::Synced => 'brand',
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
