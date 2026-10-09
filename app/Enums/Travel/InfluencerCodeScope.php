<?php

namespace App\Enums\Travel;

/**
 * Which bookings an influencer code counts on.
 */
enum InfluencerCodeScope: string
{
    case Packages = 'packages';
    case Flights = 'flights';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Packages => 'Tour & experience packages',
            self::Flights => 'Flights',
            self::All => 'Packages and flights',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Packages, self::Flights, self::All => 'neutral',
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
