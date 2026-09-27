<?php

namespace App\Policies;

use App\Enums\AccountStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;

/**
 * Account actions on people. Sales Managers can suspend and fire salespeople;
 * Sales Admins can do so for anyone except Super Admins and are the only role
 * (with Super Admin) allowed to delete. HR and Accounts can do none of these.
 * Nobody acts on their own account.
 */
class UserPolicy
{
    public function suspend(User $actor, User $target): bool
    {
        return $actor->can(Permission::SuspendUsers->value)
            && $this->inScope($actor, $target)
            && $target->accountStatus() === AccountStatus::Active;
    }

    public function terminate(User $actor, User $target): bool
    {
        return $actor->can(Permission::TerminateUsers->value)
            && $this->inScope($actor, $target)
            && $target->accountStatus() !== AccountStatus::Terminated;
    }

    /**
     * Lifting a suspension is for whoever can suspend; bringing back someone
     * who was fired is for Sales Admin / Super Admin only.
     */
    public function reinstate(User $actor, User $target): bool
    {
        return match ($target->accountStatus()) {
            AccountStatus::Suspended => $actor->can(Permission::SuspendUsers->value) && $this->inScope($actor, $target),
            AccountStatus::Terminated => $actor->can(Permission::ManageUsers->value) && $this->inScope($actor, $target),
            AccountStatus::Active => false,
        };
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->can(Permission::DeleteUsers->value) && $this->inScope($actor, $target);
    }

    /**
     * Never yourself; only a Super Admin touches a Super Admin; people without
     * "manage users" (Sales Managers) only act on salespeople.
     */
    private function inScope(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        if ($target->hasRole(Role::SuperAdmin->value) && ! $actor->hasRole(Role::SuperAdmin->value)) {
            return false;
        }

        return $actor->can(Permission::ManageUsers->value) || $target->hasRole(Role::Salesperson->value);
    }
}
