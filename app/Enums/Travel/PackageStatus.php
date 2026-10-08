<?php

namespace App\Enums\Travel;

/**
 * The lifecycle of a package as a product (not a booking or a trip).
 */
enum PackageStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Published = 'published';
    case Unpublished = 'unpublished';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending approval',
            self::Approved => 'Approved',
            self::Published => 'Published',
            self::Unpublished => 'Unpublished',
            self::Archived => 'Archived',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft, self::Unpublished, self::Archived => 'neutral',
            self::PendingApproval => 'warning',
            self::Approved => 'brand',
            self::Published => 'success',
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
