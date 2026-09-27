<?php

namespace App\Enums;

use App\Models\User;

enum Role: string
{
    case SuperAdmin = 'super-admin';
    case SalesAdmin = 'sales-admin';
    case SalesManager = 'sales-manager';
    case Salesperson = 'salesperson';
    case Hr = 'hr';
    case Accounts = 'accounts';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::SalesAdmin => 'Sales Admin',
            self::SalesManager => 'Sales Manager',
            self::Salesperson => 'Salesperson',
            self::Hr => 'HR',
            self::Accounts => 'Accounts',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Everything, including the tourlast.com connection.',
            self::SalesAdmin => 'Manages users and sees all sales data.',
            self::SalesManager => 'Sees the whole team and can invite salespeople.',
            self::Salesperson => 'Sees only their own progress, onboardings and leads.',
            self::Hr => 'Partner Register, incentive agreements and HR approval of transport claims.',
            self::Accounts => 'Partner Register, payout statements and Finance approval of claims.',
        };
    }

    /**
     * Whether people in this role receive a personal referral code.
     */
    public function earnsReferrals(): bool
    {
        return in_array($this, [self::SalesAdmin, self::SalesManager, self::Salesperson], true);
    }

    /**
     * The roles this user may invite people into or assign.
     *
     * @return list<Role>
     */
    public static function assignableBy(User $user): array
    {
        return match (true) {
            $user->hasRole(self::SuperAdmin->value) => self::cases(),
            $user->can(Permission::ManageUsers->value) => array_values(array_filter(
                self::cases(),
                fn (Role $role): bool => $role !== self::SuperAdmin,
            )),
            $user->can(Permission::InviteSalespeople->value) => [self::Salesperson],
            default => [],
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::SuperAdmin => Permission::cases(),
            self::SalesAdmin => [
                Permission::ManageUsers,
                Permission::InviteSalespeople,
                Permission::ViewTeamPerformance,
                Permission::ViewPartnerRegister,
                Permission::ExportPartnerRegister,
                Permission::ManageSettings,
                Permission::VerifyAccounts,
                Permission::ViewTeamEarnings,
                Permission::ManageAgreements,
                Permission::ApproveClaimsManager,
                Permission::ViewPaymentDetails,
                Permission::PublishAnnouncements,
                Permission::ReceiveSmartAlerts,
                Permission::ViewPresence,
                Permission::ViewEngagementRegistry,
                Permission::ManageEngagementRegistry,
                Permission::ExportEngagementRegistry,
                Permission::TransferOwnership,
                Permission::SuspendUsers,
                Permission::TerminateUsers,
                Permission::DeleteUsers,
                Permission::ManageApiTokens,
            ],
            self::SalesManager => [
                Permission::InviteSalespeople,
                Permission::ViewTeamPerformance,
                Permission::ViewPartnerRegister,
                Permission::ApproveClaimsManager,
                Permission::PublishAnnouncements,
                Permission::ReceiveSmartAlerts,
                Permission::ViewPresence,
                Permission::ViewEngagementRegistry,
                Permission::ManageEngagementRegistry,
                Permission::ExportEngagementRegistry,
                Permission::TransferOwnership,
                Permission::SuspendUsers,
                Permission::TerminateUsers,
            ],
            self::Salesperson => [
                Permission::ViewEngagementRegistry,
            ],
            self::Hr => [
                Permission::ViewPartnerRegister,
                Permission::ExportPartnerRegister,
                Permission::ViewTeamEarnings,
                Permission::ManageAgreements,
                Permission::ApproveClaimsHr,
                Permission::ViewPaymentDetails,
                Permission::PublishAnnouncements,
                Permission::ViewPresence,
                Permission::ViewEngagementRegistry,
            ],
            self::Accounts => [
                Permission::ViewPartnerRegister,
                Permission::ExportPartnerRegister,
                Permission::ViewTeamEarnings,
                Permission::ManagePayouts,
                Permission::ApproveClaimsFinance,
                Permission::ViewPaymentDetails,
                Permission::PublishAnnouncements,
                Permission::ViewPresence,
                Permission::ViewEngagementRegistry,
            ],
        };
    }
}
