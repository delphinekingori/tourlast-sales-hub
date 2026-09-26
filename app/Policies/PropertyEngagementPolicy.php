<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\PropertyEngagement;
use App\Models\User;

/**
 * Everyone with registry access can search and read it. Only managers (Super
 * Admin, Sales Admin, Sales Manager) can change it or export it.
 */
class PropertyEngagementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ViewEngagementRegistry->value);
    }

    /**
     * Archived records are visible only to the people who can restore them.
     */
    public function view(User $user, PropertyEngagement $engagement): bool
    {
        return $engagement->trashed()
            ? $this->manage($user)
            : $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    /**
     * Edit details, log engagement, change stage/status, reassign, manage contacts and links.
     */
    public function update(User $user, PropertyEngagement $engagement): bool
    {
        return $this->manage($user) && ! $engagement->trashed();
    }

    /**
     * Archive (soft delete). History is kept.
     */
    public function delete(User $user, PropertyEngagement $engagement): bool
    {
        return $this->manage($user);
    }

    public function restore(User $user, PropertyEngagement $engagement): bool
    {
        return $this->manage($user);
    }

    public function export(User $user): bool
    {
        return $user->can(Permission::ExportEngagementRegistry->value);
    }

    private function manage(User $user): bool
    {
        return $user->can(Permission::ManageEngagementRegistry->value);
    }
}
