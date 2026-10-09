<?php

namespace App\Enums;

enum Permission: string
{
    case ManageUsers = 'manage-users';
    case InviteSalespeople = 'invite-salespeople';
    case ViewTeamPerformance = 'view-team-performance';
    case ViewPartnerRegister = 'view-partner-register';
    case ExportPartnerRegister = 'export-partner-register';
    case ManageSettings = 'manage-settings';
    case ManageIntegration = 'manage-integration';

    /** Verify Partner Accounts, approve points, run the 14-day review. */
    case VerifyAccounts = 'verify-accounts';

    /** See everyone's pay amounts and statements. */
    case ViewTeamEarnings = 'view-team-earnings';

    /** Approve, freeze and mark statements paid. */
    case ManagePayouts = 'manage-payouts';

    /** Set up and end salespeople's incentive agreements. */
    case ManageAgreements = 'manage-agreements';

    /** See everyone's M-Pesa and bank payout details. */
    case ViewPaymentDetails = 'view-payment-details';

    /** Write announcements to the team. */
    case PublishAnnouncements = 'publish-announcements';

    /** Receive Smart Alerts about partners, deals, follow-ups and contracts. */
    case ReceiveSmartAlerts = 'receive-smart-alerts';

    /** See who is online and open the People directory. */
    case ViewPresence = 'view-presence';

    /** Search and open the Property Engagement Registry (read only). */
    case ViewEngagementRegistry = 'view-engagement-registry';

    /** Add, edit, archive registry records and log engagement on them. */
    case ManageEngagementRegistry = 'manage-engagement-registry';

    /** Download the registry as Excel and the engagement report. */
    case ExportEngagementRegistry = 'export-engagement-registry';

    /** Suspend and reinstate accounts (Sales Managers: salespeople only). */
    case SuspendUsers = 'suspend-users';

    /** Fire (terminate) accounts. History is kept. */
    case TerminateUsers = 'terminate-users';

    /** Permanently delete accounts that have no business history. */
    case DeleteUsers = 'delete-users';

    /** Issue and revoke API tokens for anyone (Admin → API tokens). */
    case ManageApiTokens = 'manage-api-tokens';

    /** Edit a salesperson's referral code; the source apps pull the list from the Hub. */
    case ManageRefCodes = 'manage-ref-codes';

    /** Assign and transfer leads between salespeople. */
    case TransferOwnership = 'transfer-ownership';

    /** Open the Travel Sales workspace and manage your own providers, packages, bookings and influencers. */
    case AccessTravelSales = 'access-travel-sales';

    /** See and manage every travel salesperson's records, not only your own. */
    case ManageTravelSales = 'manage-travel-sales';

    /** First-level package approval (Sales Admin review). */
    case ApprovePackagesFirst = 'approve-packages-first';

    /** Final package approval (Super Admin review). */
    case ApprovePackagesFinal = 'approve-packages-final';

    /** Publish a package whose provider contract is not active, with a recorded reason. */
    case OverridePackageContract = 'override-package-contract';

    /** See provider prices, net rates, commission and margins on packages and contracts. */
    case ViewTravelFinancials = 'view-travel-financials';

    /** Confirm cash and bank payments, allocate unmatched M-Pesa payments and pay out refunds. */
    case ManageTravelPayments = 'manage-travel-payments';

    /** Approve or reject travel refund and cancellation requests. */
    case ApproveTravelRefunds = 'approve-travel-refunds';

    /** Set monthly travel targets for travel salespeople. */
    case ManageTravelTargets = 'manage-travel-targets';

    /** Send flight bookings to the Hub (the Flights Super Admin integration account only). */
    case PushFlightBookings = 'push-flight-bookings';

    /** Read the system-wide audit log. */
    case ViewAuditLog = 'view-audit-log';

    case ApproveClaimsManager = 'approve-claims-manager';
    case ApproveClaimsHr = 'approve-claims-hr';
    case ApproveClaimsFinance = 'approve-claims-finance';
}
