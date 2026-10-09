<?php

namespace App\Enums\Travel;

/**
 * A refund to a package client.
 */
enum RefundStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Approved => 'Approved',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Rejected => 'Rejected',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Requested => 'warning',
            self::Approved, self::Processing => 'brand',
            self::Completed => 'success',
            self::Failed, self::Rejected => 'danger',
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
