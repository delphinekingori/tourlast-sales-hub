<?php

namespace App\Enums;

/**
 * Which Tourlast product a tracked referral link sends a provider to.
 */
enum ReferralTarget: string
{
    case Stays = 'stays';
    case Experiences = 'experiences';

    public function label(): string
    {
        return match ($this) {
            self::Stays => 'Stays',
            self::Experiences => 'Experiences',
        };
    }

    /**
     * The registration page on tourlast.com for this product, before the code is attached.
     */
    public function registrationUrl(): string
    {
        return match ($this) {
            self::Stays => config('hub.list_property_url'),
            self::Experiences => config('hub.list_experiences_url'),
        };
    }

    /**
     * The sentence a salesperson shares along with the link.
     */
    public function shareMessage(string $url): string
    {
        return match ($this) {
            self::Stays => "List your property on Tourlast: {$url}",
            self::Experiences => "List your experiences on Tourlast: {$url}",
        };
    }
}
