<?php

namespace App\Enums\Travel;

/**
 * Whether a media file may be used on packages.
 */
enum MediaUsagePermission: string
{
    case Granted = 'granted';
    case Restricted = 'restricted';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Granted => 'Cleared for use',
            self::Restricted => 'Restricted use',
            self::Revoked => 'Permission revoked',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Granted => 'success',
            self::Restricted => 'warning',
            self::Revoked => 'danger',
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
