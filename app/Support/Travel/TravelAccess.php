<?php

namespace App\Support\Travel;

use App\Enums\Permission;
use App\Models\User;

/**
 * Who may do what in Travel Sales. Every Livewire page, controller and action
 * in the workspace checks through here (never only in the view).
 *
 * - Travel salespeople work on their own records and read the shared catalogue.
 * - Travel managers (Sales Admin, Super Admin) see and manage everyone's.
 * - Accounts handle payments and refunds only.
 * - Sales Managers and HR have no access at all.
 */
class TravelAccess
{
    public static function works(User $user): bool
    {
        return $user->can(Permission::AccessTravelSales->value);
    }

    public static function managesAll(User $user): bool
    {
        return $user->can(Permission::ManageTravelSales->value);
    }

    /**
     * May change a record owned by $ownerId.
     */
    public static function canChange(User $user, ?int $ownerId): bool
    {
        return self::works($user) && (self::managesAll($user) || ($ownerId !== null && $ownerId === $user->id));
    }

    public static function seesFinancials(User $user): bool
    {
        return $user->can(Permission::ViewTravelFinancials->value);
    }

    public static function handlesPayments(User $user): bool
    {
        return $user->can(Permission::ManageTravelPayments->value);
    }

    public static function abortUnlessWorks(User $user): void
    {
        abort_unless(self::works($user), 403);
    }

    public static function abortUnlessCanChange(User $user, ?int $ownerId): void
    {
        abort_unless(self::canChange($user, $ownerId), 403);
    }
}
