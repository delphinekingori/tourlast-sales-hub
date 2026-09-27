<?php

namespace App\Enums;

/**
 * The current condition of an engagement, independent of its stage.
 */
enum EngagementStatus: string
{
    case Active = 'active';
    case Stalled = 'stalled';
    case Won = 'won';
    case Lost = 'lost';
    case Rejected = 'rejected';
    case ReEngage = 're_engage';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Stalled => 'Stalled',
            self::Won => 'Won',
            self::Lost => 'Lost',
            self::Rejected => 'Rejected',
            self::ReEngage => 'Re-engage',
            self::Closed => 'Closed',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'brand',
            self::Stalled, self::ReEngage => 'warning',
            self::Won => 'success',
            self::Lost, self::Rejected => 'danger',
            self::Closed => 'neutral',
        };
    }

    /**
     * Someone is still working on the property.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Active, self::Stalled, self::ReEngage], true);
    }
}
