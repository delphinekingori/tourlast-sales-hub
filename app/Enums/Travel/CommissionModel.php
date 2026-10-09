<?php

namespace App\Enums\Travel;

/**
 * How Tourlast earns on a provider contract.
 */
enum CommissionModel: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
    case NetRate = 'net_rate';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage of sale',
            self::Fixed => 'Fixed amount per booking',
            self::NetRate => 'Net rate (Tourlast sets the selling price)',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Percentage, self::Fixed, self::NetRate => 'neutral',
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
