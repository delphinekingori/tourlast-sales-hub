<?php

namespace App\Enums;

enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * The status pill tone used in the interface.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Accepted => 'success',
            self::Expired => 'neutral',
            self::Revoked => 'danger',
        };
    }
}
