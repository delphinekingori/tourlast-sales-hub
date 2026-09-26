<?php

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Meeting = 'meeting';
    case LinkSent = 'link_sent';
    case Onboarded = 'onboarded';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Meeting => 'Meeting',
            self::LinkSent => 'Link sent',
            self::Onboarded => 'Onboarded',
            self::Lost => 'Lost',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::New => 'neutral',
            self::Contacted, self::Meeting => 'brand',
            self::LinkSent => 'warning',
            self::Onboarded => 'success',
            self::Lost => 'danger',
        };
    }

    /**
     * Statuses a salesperson can pick by hand. "Onboarded" is set only when
     * a matching signup arrives from tourlast.com.
     *
     * @return list<LeadStatus>
     */
    public static function manual(): array
    {
        return [self::New, self::Contacted, self::Meeting, self::LinkSent, self::Lost];
    }

    /**
     * @return list<LeadStatus>
     */
    public static function open(): array
    {
        return [self::New, self::Contacted, self::Meeting, self::LinkSent];
    }
}
