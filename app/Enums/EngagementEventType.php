<?php

namespace App\Enums;

/**
 * Entries on a registry record's timeline. Interactions are logged by
 * managers; the rest are written automatically and double as the audit log.
 */
enum EngagementEventType: string
{
    case Created = 'created';
    case Call = 'call';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Meeting = 'meeting';
    case SiteVisit = 'site_visit';
    case Demo = 'demo';
    case Proposal = 'proposal';
    case Negotiation = 'negotiation';
    case Note = 'note';
    case StageChanged = 'stage_changed';
    case StatusChanged = 'status_changed';
    case RepChanged = 'rep_changed';
    case Edited = 'edited';
    case ContactAdded = 'contact_added';
    case ContactRemoved = 'contact_removed';
    case Linked = 'linked';
    case Onboarding = 'onboarding';
    case Archived = 'archived';
    case Restored = 'restored';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Added to the registry',
            self::Call => 'Call',
            self::WhatsApp => 'WhatsApp contact',
            self::Email => 'Email',
            self::Meeting => 'Meeting',
            self::SiteVisit => 'Site visit',
            self::Demo => 'Demo / Presentation',
            self::Proposal => 'Proposal',
            self::Negotiation => 'Negotiation',
            self::Note => 'Note',
            self::StageChanged => 'Stage changed',
            self::StatusChanged => 'Status changed',
            self::RepChanged => 'Sales representative changed',
            self::Edited => 'Details edited',
            self::ContactAdded => 'Contact added',
            self::ContactRemoved => 'Contact removed',
            self::Linked => 'Record linked',
            self::Onboarding => 'tourlast.com onboarding update',
            self::Archived => 'Archived',
            self::Restored => 'Restored',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Call => 'phone',
            self::WhatsApp => 'chat',
            self::Email, self::Proposal => 'mail',
            self::Meeting, self::Negotiation => 'users',
            self::SiteVisit => 'building',
            self::Demo => 'chart',
            self::StageChanged, self::StatusChanged, self::Onboarding => 'arrow-right',
            self::RepChanged, self::ContactAdded, self::ContactRemoved => 'user',
            self::Linked => 'link',
            self::Archived, self::Restored => 'lock',
            default => 'clipboard',
        };
    }

    /**
     * A touchpoint with the property (as opposed to a record change).
     * Interactions move the "last engaged" date.
     */
    public function isInteraction(): bool
    {
        return in_array($this, self::interactions(), true);
    }

    /**
     * @return list<EngagementEventType>
     */
    public static function interactions(): array
    {
        return [self::Call, self::WhatsApp, self::Email, self::Meeting, self::SiteVisit, self::Demo, self::Proposal, self::Negotiation, self::Note];
    }
}
