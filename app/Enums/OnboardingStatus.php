<?php

namespace App\Enums;

enum OnboardingStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Active = 'active';
    case Inactive = 'inactive';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::Approved => 'Approved, going live',
            self::Active => 'Live',
            self::Inactive => 'Inactive',
            self::Rejected => 'Rejected',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Submitted => 'neutral',
            self::UnderReview => 'warning',
            self::Approved => 'brand',
            self::Active => 'success',
            self::Inactive => 'warning',
            self::Rejected => 'danger',
        };
    }

    /**
     * Schedule 1: an Account counts from its Activation Date, when it is live
     * and ready to receive bookings. An inactive property went live and later
     * stopped, so it is history: it is no longer counted as live, but only a
     * rejection withdraws the credit it earned.
     */
    public function isOnboarded(): bool
    {
        return $this === self::Active;
    }

    /**
     * Signed up on tourlast.com but not live yet.
     */
    public function isAwaitingApproval(): bool
    {
        return in_array($this, [self::Submitted, self::UnderReview, self::Approved], true);
    }

    /**
     * @return list<string>
     */
    public static function onboardedValues(): array
    {
        return [self::Active->value];
    }

    /**
     * @return list<string>
     */
    public static function awaitingValues(): array
    {
        return [self::Submitted->value, self::UnderReview->value, self::Approved->value];
    }
}
