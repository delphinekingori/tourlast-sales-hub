<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Terminated => 'Fired',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Suspended => 'warning',
            self::Terminated => 'danger',
        };
    }

    /**
     * Reasons offered when suspending.
     *
     * @return array<string, string>
     */
    public static function suspensionReasons(): array
    {
        return [
            'investigation' => 'Under investigation',
            'conduct' => 'Conduct / policy breach',
            'performance' => 'Performance review',
            'leave' => 'Extended leave',
            'other' => 'Other',
        ];
    }

    /**
     * Reasons offered when firing.
     *
     * @return array<string, string>
     */
    public static function terminationReasons(): array
    {
        return [
            'misconduct' => 'Gross misconduct',
            'performance' => 'Poor performance',
            'contract_ended' => 'Contract ended',
            'redundancy' => 'Redundancy',
            'resigned' => 'Resigned',
            'other' => 'Other',
        ];
    }

    public static function reasonLabel(?string $reason): ?string
    {
        return $reason === null ? null : (self::suspensionReasons()[$reason] ?? self::terminationReasons()[$reason] ?? $reason);
    }
}
