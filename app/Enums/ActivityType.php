<?php

namespace App\Enums;

enum ActivityType: string
{
    case Call = 'call';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Meeting = 'meeting';
    case SiteVisit = 'site_visit';
    case Demo = 'demo';
    case ProposalSent = 'proposal_sent';
    case ContractDiscussion = 'contract_discussion';
    case FollowUp = 'follow_up';

    // Travel Sales (calendar and follow-ups only)
    case FlightFollowUp = 'flight_follow_up';
    case CustomerMeeting = 'customer_meeting';
    case CustomerFollowUp = 'customer_follow_up';
    case ProviderMeeting = 'provider_meeting';
    case ContractMeeting = 'contract_meeting';
    case PackageReview = 'package_review';
    case PreTripBriefing = 'pre_trip_briefing';
    case PartnerCheckIn = 'partner_check_in';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Call',
            self::WhatsApp => 'WhatsApp',
            self::Email => 'Email',
            self::Meeting => 'Meeting',
            self::SiteVisit => 'Site visit',
            self::Demo => 'Demo',
            self::ProposalSent => 'Proposal sent',
            self::ContractDiscussion => 'Contract discussion',
            self::FollowUp => 'Follow-up',
            self::FlightFollowUp => 'Flight follow-up',
            self::CustomerMeeting => 'Customer meeting',
            self::CustomerFollowUp => 'Customer follow-up',
            self::ProviderMeeting => 'Provider meeting',
            self::ContractMeeting => 'Contract meeting',
            self::PackageReview => 'Package review',
            self::PreTripBriefing => 'Pre-trip briefing',
            self::PartnerCheckIn => 'Partner check-in',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Call => 'phone',
            self::WhatsApp => 'chat',
            self::Email, self::ProposalSent => 'mail',
            self::Meeting, self::ContractDiscussion, self::CustomerMeeting, self::ProviderMeeting => 'users',
            self::SiteVisit => 'building',
            self::Demo => 'chart',
            self::FollowUp, self::CustomerFollowUp => 'clock',
            self::FlightFollowUp => 'plane',
            self::ContractMeeting => 'document',
            self::PackageReview => 'map',
            self::PreTripBriefing => 'clipboard',
            self::PartnerCheckIn => 'check-circle',
        };
    }

    /**
     * Types for property sales (leads and the registry).
     *
     * @return list<self>
     */
    public static function forProperty(): array
    {
        return [self::Call, self::WhatsApp, self::Email, self::Meeting, self::SiteVisit, self::Demo, self::ProposalSent, self::ContractDiscussion, self::FollowUp];
    }

    /**
     * Types a travel salesperson schedules.
     *
     * @return list<self>
     */
    public static function forTravel(): array
    {
        return [
            self::CustomerFollowUp, self::FlightFollowUp, self::CustomerMeeting, self::ProviderMeeting, self::ContractMeeting,
            self::PackageReview, self::PreTripBriefing, self::PartnerCheckIn, self::Call, self::WhatsApp, self::Email,
        ];
    }
}
