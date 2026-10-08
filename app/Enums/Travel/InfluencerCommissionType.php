<?php

namespace App\Enums\Travel;

/**
 * How an influencer code earns.
 */
enum InfluencerCommissionType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage of booking',
            self::Fixed => 'Fixed amount per booking',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Percentage, self::Fixed => 'neutral',
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
