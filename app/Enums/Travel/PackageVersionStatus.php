<?php

namespace App\Enums\Travel;

/**
 * The review state of one version of a package.
 */
enum PackageVersionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case SalesAdminApproved = 'sales_admin_approved';
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Awaiting Sales Admin',
            self::SalesAdminApproved => 'Awaiting Super Admin',
            self::Approved => 'Approved',
            self::ChangesRequested => 'Changes requested',
            self::Rejected => 'Rejected',
            self::Superseded => 'Superseded',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft, self::Superseded => 'neutral',
            self::Submitted, self::SalesAdminApproved, self::ChangesRequested => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
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
