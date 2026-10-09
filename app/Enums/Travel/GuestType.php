<?php

namespace App\Enums\Travel;

/**
 * Age band of a guest on a package booking (matches the booking's adults,
 * children and infants counts).
 */
enum GuestType: string
{
    case Adult = 'adult';
    case Child = 'child';
    case Infant = 'infant';

    public function label(): string
    {
        return match ($this) {
            self::Adult => 'Adult',
            self::Child => 'Child',
            self::Infant => 'Infant',
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
