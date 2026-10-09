<?php

namespace App\Enums\Travel;

/**
 * An influencer commission line.
 */
enum CommissionEntryStatus: string
{
    case Pending = 'pending';
    case Payable = 'payable';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending (booking not fully paid)',
            self::Payable => 'Payable',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Payable => 'brand',
            self::Paid => 'success',
            self::Cancelled => 'neutral',
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
