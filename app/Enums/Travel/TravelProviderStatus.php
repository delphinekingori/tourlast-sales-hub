<?php

namespace App\Enums\Travel;

/**
 * Where the relationship with a provider stands.
 */
enum TravelProviderStatus: string
{
    case Prospect = 'prospect';
    case Contacted = 'contacted';
    case Interested = 'interested';
    case Negotiation = 'negotiation';
    case Contracted = 'contracted';
    case Active = 'active';
    case Suspended = 'suspended';
    case Inactive = 'inactive';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Prospect => 'Prospect',
            self::Contacted => 'Contacted',
            self::Interested => 'Interested',
            self::Negotiation => 'Contract negotiation',
            self::Contracted => 'Contracted',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Inactive => 'Inactive',
            self::Terminated => 'Terminated',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Prospect, self::Inactive => 'neutral',
            self::Contacted, self::Interested => 'brand',
            self::Negotiation, self::Suspended => 'warning',
            self::Contracted, self::Active => 'success',
            self::Terminated => 'danger',
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
