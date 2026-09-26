<?php

namespace App\Incentives;

/**
 * Schedule 1, paragraph 10: the Account Qualification Checklist.
 */
enum ChecklistItem: string
{
    case ReferralRecorded = 'referral_recorded';
    case AuthorityConfirmed = 'authority_confirmed';
    case AgreementSigned = 'agreement_signed';
    case DetailsVerified = 'details_verified';
    case FullyConfigured = 'fully_configured';
    case InventoryAccurate = 'inventory_accurate';
    case PricesEntered = 'prices_entered';
    case ContentComplete = 'content_complete';
    case PoliciesComplete = 'policies_complete';
    case PartnerTrained = 'partner_trained';
    case PartnerUnderstands = 'partner_understands';
    case ProvisionallyApproved = 'provisionally_approved';
    case LiveAndBookable = 'live_and_bookable';

    public function label(): string
    {
        return match ($this) {
            self::ReferralRecorded => 'Individual referral link recorded',
            self::AuthorityConfirmed => 'Partner authority confirmed',
            self::AgreementSigned => 'Correct agreement signed',
            self::DetailsVerified => 'Legal and business details verified',
            self::FullyConfigured => 'Account fully configured',
            self::InventoryAccurate => 'Rooms, units or services entered accurately',
            self::PricesEntered => 'Prices, rates and availability entered',
            self::ContentComplete => 'Images and descriptions complete',
            self::PoliciesComplete => 'Policies, cancellation terms and operating information complete',
            self::PartnerTrained => 'Partner trained and able to log in',
            self::PartnerUnderstands => 'Partner understands booking, payment, settlement, cancellation and support basics',
            self::ProvisionallyApproved => 'Account provisionally approved by Tourlast',
            self::LiveAndBookable => 'Account live and ready to receive bookings',
        };
    }

    /**
     * Items the Hub can tick from tourlast.com data.
     */
    public function isAutomatic(): bool
    {
        return in_array($this, [self::ReferralRecorded, self::ProvisionallyApproved, self::LiveAndBookable], true);
    }

    /**
     * Items the salesperson is expected to back with evidence.
     */
    public function wantsEvidence(): bool
    {
        return in_array($this, [self::AuthorityConfirmed, self::AgreementSigned, self::DetailsVerified, self::PartnerTrained], true);
    }
}
