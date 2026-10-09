<?php

namespace App\Support\Travel;

use App\Models\PackageBooking;
use App\Models\User;

/**
 * Who may see and take payments on package bookings. Accounts (ManageTravelPayments)
 * and Travel managers see every payment; a travel salesperson sees and
 * collects only on bookings they sold or bookings on their own packages.
 * Confirming cash/bank payments and allocating unmatched M-Pesa payments is
 * for Accounts only.
 */
class PaymentAccess
{
    public static function opensPayments(User $user): bool
    {
        return TravelAccess::works($user) || TravelAccess::handlesPayments($user);
    }

    public static function seesAll(User $user): bool
    {
        return TravelAccess::managesAll($user) || TravelAccess::handlesPayments($user);
    }

    public static function canCollect(User $user, PackageBooking $booking): bool
    {
        if (self::seesAll($user)) {
            return true;
        }

        return TravelAccess::works($user)
            && ($booking->salesperson_id === $user->id || $booking->package?->owner_id === $user->id);
    }

    public static function canView(User $user, PackageBooking $booking): bool
    {
        return self::canCollect($user, $booking);
    }

    /**
     * Confirm or reject cash/bank payments, allocate unmatched M-Pesa payments.
     */
    public static function confirms(User $user): bool
    {
        return TravelAccess::handlesPayments($user);
    }
}
