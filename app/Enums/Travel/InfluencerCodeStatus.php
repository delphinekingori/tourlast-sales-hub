<?php

namespace App\Enums\Travel;

/**
 * Whether an influencer code is in use.
 */
enum InfluencerCodeStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Ended => 'Ended',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Paused => 'warning',
            self::Ended => 'neutral',
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
