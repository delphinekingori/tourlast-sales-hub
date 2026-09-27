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
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Call => 'phone',
            self::WhatsApp => 'chat',
            self::Email, self::ProposalSent => 'mail',
            self::Meeting, self::ContractDiscussion => 'users',
            self::SiteVisit => 'building',
            self::Demo => 'chart',
            self::FollowUp => 'clock',
        };
    }
}
