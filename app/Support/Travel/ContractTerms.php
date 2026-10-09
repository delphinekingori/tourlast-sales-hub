<?php

namespace App\Support\Travel;

use App\Enums\Role;
use App\Enums\Travel\ContractStatus;
use App\Models\ProviderContract;
use App\Models\TravelProvider;
use App\Models\User;

/**
 * Who may edit a provider contract, see its commission, and move it through
 * Draft → Pending review → Pending approval → Active (→ Suspended / Terminated).
 */
class ContractTerms
{
    /** Status moves: action => [allowed from, to, label]. */
    public const Transitions = [
        'submit_review' => [[ContractStatus::Draft], ContractStatus::PendingReview, 'Send for review'],
        'submit_approval' => [[ContractStatus::PendingReview], ContractStatus::PendingApproval, 'Send for approval'],
        'approve' => [[ContractStatus::PendingApproval], ContractStatus::Active, 'Approve'],
        'return_draft' => [[ContractStatus::PendingReview, ContractStatus::PendingApproval], ContractStatus::Draft, 'Send back to draft'],
        'suspend' => [[ContractStatus::Active], ContractStatus::Suspended, 'Suspend'],
        'reactivate' => [[ContractStatus::Suspended], ContractStatus::Active, 'Reactivate'],
        'terminate' => [[ContractStatus::Draft, ContractStatus::PendingReview, ContractStatus::PendingApproval, ContractStatus::Active, ContractStatus::Suspended], ContractStatus::Terminated, 'Terminate'],
    ];

    public static function canEdit(User $user, TravelProvider $provider, ?ProviderContract $contract = null): bool
    {
        if (! TravelAccess::canChange($user, $provider->owner_id)) {
            return false;
        }

        if ($contract === null) {
            return true;
        }

        if ($contract->status === ContractStatus::Terminated) {
            return false;
        }

        return TravelAccess::managesAll($user)
            || in_array($contract->status, [ContractStatus::Draft, ContractStatus::PendingReview], true);
    }

    /**
     * Commission figures: people with "view travel financials", plus the
     * salesperson who owns the provider (they negotiated the terms).
     */
    public static function seesCommission(User $user, TravelProvider $provider): bool
    {
        return TravelAccess::seesFinancials($user)
            || (TravelAccess::works($user) && $provider->owner_id === $user->id);
    }

    /**
     * Contract documents (rates, signed terms) follow the commission rule.
     */
    public static function seesDocuments(User $user, TravelProvider $provider): bool
    {
        return TravelAccess::works($user) && self::seesCommission($user, $provider);
    }

    /**
     * Add, replace or remove contract documents: people who see them and
     * may change the provider (its salesperson, Travel managers).
     */
    public static function canAttach(User $user, TravelProvider $provider): bool
    {
        return self::seesDocuments($user, $provider) && TravelAccess::canChange($user, $provider->owner_id);
    }

    public static function can(User $user, ProviderContract $contract, string $action): bool
    {
        $move = self::Transitions[$action] ?? null;
        $provider = $contract->provider;

        if (! $move || ! in_array($contract->status, $move[0], true) || ! TravelAccess::works($user)) {
            return false;
        }

        return match ($action) {
            'submit_review', 'submit_approval' => TravelAccess::canChange($user, $provider->owner_id),
            // Whoever wrote the contract cannot approve it (Super Admin excepted).
            'approve' => TravelAccess::managesAll($user)
                && ($contract->created_by !== $user->id || $user->hasRole(Role::SuperAdmin->value)),
            default => TravelAccess::managesAll($user),
        };
    }

    /**
     * Actions the user may take on the contract now, action => label.
     *
     * @return array<string, string>
     */
    public static function available(User $user, ProviderContract $contract): array
    {
        $actions = [];

        foreach (self::Transitions as $action => [, , $label]) {
            if (self::can($user, $contract, $action)) {
                $actions[$action] = $label;
            }
        }

        return $actions;
    }
}
