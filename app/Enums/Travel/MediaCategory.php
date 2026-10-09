<?php

namespace App\Enums\Travel;

/**
 * Folders in the Media Gallery.
 */
enum MediaCategory: string
{
    case Lodges = 'lodges';
    case Wildlife = 'wildlife';
    case Vehicles = 'vehicles';
    case Activities = 'activities';
    case Guides = 'guides';
    case Landscapes = 'landscapes';
    case Food = 'food';
    case Experiences = 'experiences';
    case Documents = 'documents';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Lodges => 'Lodges',
            self::Wildlife => 'Wildlife',
            self::Vehicles => 'Vehicles',
            self::Activities => 'Activities',
            self::Guides => 'Guides',
            self::Landscapes => 'Landscapes',
            self::Food => 'Food',
            self::Experiences => 'Experiences',
            self::Documents => 'Documents',
            self::Other => 'Other',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Lodges, self::Wildlife, self::Vehicles, self::Activities, self::Guides, self::Landscapes, self::Food, self::Experiences, self::Documents, self::Other => 'neutral',
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
