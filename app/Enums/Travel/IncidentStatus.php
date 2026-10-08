<?php

namespace App\Enums\Travel;

/**
 * Where a provider incident stands.
 */
enum IncidentStatus: string
{
    case Open = 'open';
    case Investigating = 'investigating';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Investigating => 'Investigating',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Investigating => 'warning',
            self::Resolved => 'success',
            self::Closed => 'neutral',
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
