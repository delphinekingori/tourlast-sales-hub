<?php

namespace App\Enums\Travel;

/**
 * The kind of tour or experience partner.
 */
enum TravelProviderType: string
{
    case TourOperator = 'tour_operator';
    case SafariOperator = 'safari_operator';
    case ExperienceProvider = 'experience_provider';
    case ActivityProvider = 'activity_provider';
    case Dmc = 'dmc';
    case TransportProvider = 'transport_provider';
    case GuideCompany = 'guide_company';
    case AdventureCompany = 'adventure_company';
    case Attraction = 'attraction';
    case Dining = 'dining';
    case EventVenue = 'event_venue';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::TourOperator => 'Tour operator',
            self::SafariOperator => 'Safari operator',
            self::ExperienceProvider => 'Experience provider',
            self::ActivityProvider => 'Activity provider',
            self::Dmc => 'DMC',
            self::TransportProvider => 'Transport provider',
            self::GuideCompany => 'Guide company',
            self::AdventureCompany => 'Adventure company',
            self::Attraction => 'Attraction',
            self::Dining => 'Restaurant / dining experience',
            self::EventVenue => 'Event / venue',
            self::Other => 'Other',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::TourOperator, self::SafariOperator, self::ExperienceProvider, self::ActivityProvider, self::Dmc, self::TransportProvider, self::GuideCompany, self::AdventureCompany, self::Attraction, self::Dining, self::EventVenue, self::Other => 'neutral',
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
