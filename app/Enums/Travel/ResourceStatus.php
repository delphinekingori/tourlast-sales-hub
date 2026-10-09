<?php

namespace App\Enums\Travel;

/**
 * Whether a driver or guide can be assigned.
 */
enum ResourceStatus: string
{
    case Active = 'active';
    case Unavailable = 'unavailable';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Unavailable => 'Unavailable',
            self::Inactive => 'Inactive',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Unavailable => 'warning',
            self::Inactive => 'neutral',
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
