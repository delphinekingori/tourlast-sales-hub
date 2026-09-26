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

    /** Assign and transfer leads between salespeople. */
    case TransferOwnership = 'transfer-ownership';

    case ApproveClaimsManager = 'approve-claims-manager';
    case ApproveClaimsHr = 'approve-claims-hr';
    case ApproveClaimsFinance = 'approve-claims-finance';
}
