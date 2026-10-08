<?php

namespace App\Enums\Travel;

/**
 * A single payment.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Reversed => 'Reversed',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Completed => 'success',
            self::Failed, self::Reversed => 'danger',
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
