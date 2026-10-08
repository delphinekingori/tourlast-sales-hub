<?php

namespace App\Enums\Travel;

/**
 * A package cancellation request.
 */
enum CancellationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Processed = 'processed';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Processed => 'Processed',
            self::Completed => 'Completed',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved, self::Processed => 'brand',
            self::Rejected => 'danger',
            self::Completed => 'success',
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
