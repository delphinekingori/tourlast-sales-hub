<?php

namespace App\Enums\Travel;

/**
 * Kinds of provider quality incident.
 */
enum IncidentType: string
{
    case DriverNoShow = 'driver_no_show';
    case GuideIssue = 'guide_issue';
    case VehicleIssue = 'vehicle_issue';
    case CustomerComplaint = 'customer_complaint';
    case PackageMismatch = 'package_mismatch';
    case SafetyConcern = 'safety_concern';
    case ProviderCancellation = 'provider_cancellation';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DriverNoShow => 'Driver no-show',
            self::GuideIssue => 'Guide issue',
            self::VehicleIssue => 'Vehicle issue',
            self::CustomerComplaint => 'Customer complaint',
            self::PackageMismatch => 'Package mismatch',
            self::SafetyConcern => 'Safety concern',
            self::ProviderCancellation => 'Provider cancellation',
            self::Other => 'Other',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::DriverNoShow, self::GuideIssue, self::VehicleIssue, self::CustomerComplaint, self::PackageMismatch, self::SafetyConcern, self::ProviderCancellation, self::Other => 'neutral',
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
