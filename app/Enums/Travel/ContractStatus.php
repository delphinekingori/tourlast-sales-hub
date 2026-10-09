<?php

namespace App\Enums\Travel;

/**
 * The stored status of a provider contract. "Expiring soon" and "Expired" are worked out from the end date (see ProviderContract::effectiveStatus).
 */
enum ContractStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case ExpiringSoon = 'expiring_soon';
    case Expired = 'expired';
    case Suspended = 'suspended';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingReview => 'Pending review',
            self::PendingApproval => 'Pending approval',
            self::Active => 'Active',
            self::ExpiringSoon => 'Expiring soon',
            self::Expired => 'Expired',
            self::Suspended => 'Suspended',
            self::Terminated => 'Terminated',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::PendingReview, self::PendingApproval, self::ExpiringSoon, self::Suspended => 'warning',
            self::Active => 'success',
            self::Expired, self::Terminated => 'danger',
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
