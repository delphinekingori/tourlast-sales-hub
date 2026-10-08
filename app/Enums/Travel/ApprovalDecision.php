<?php

namespace App\Enums\Travel;

/**
 * The outcome of one package review.
 */
enum ApprovalDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case ChangesRequested = 'changes_requested';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::ChangesRequested => 'Changes requested',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::ChangesRequested => 'warning',
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
