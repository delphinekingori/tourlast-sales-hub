<?php

namespace App\Enums;

/**
 * Where an engagement is in the acquisition process. Its condition (active,
 * stalled, lost...) is tracked separately as an EngagementStatus.
 */
enum EngagementStage: string
{
    case NotContacted = 'not_contacted';
    case Contacted = 'contacted';
    case Interested = 'interested';
    case MeetingScheduled = 'meeting_scheduled';
    case MeetingCompleted = 'meeting_completed';
    case Demo = 'demo';
    case ProposalSent = 'proposal_sent';
    case Negotiation = 'negotiation';
    case OnboardingStarted = 'onboarding_started';
    case OnboardingSubmitted = 'onboarding_submitted';
    case Verification = 'verification';
    case Approved = 'approved';
    case Live = 'live';

    public function label(): string
    {
        return match ($this) {
            self::NotContacted => 'Not contacted',
            self::Contacted => 'Contacted',
            self::Interested => 'Interested',
            self::MeetingScheduled => 'Meeting scheduled',
            self::MeetingCompleted => 'Meeting completed',
            self::Demo => 'Demo / Presentation',
            self::ProposalSent => 'Proposal sent',
            self::Negotiation => 'Negotiation',
            self::OnboardingStarted => 'Onboarding started',
            self::OnboardingSubmitted => 'Onboarding submitted',
            self::Verification => 'Verification',
            self::Approved => 'Approved',
            self::Live => 'Live',
        };
    }

    public function tone(): string
    {
        return match (true) {
            $this === self::Live => 'success',
            $this->isOnboarding() => 'brand',
            default => 'neutral',
        };
    }

    /**
     * Position in the process, used for sorting.
     */
    public function order(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    /**
     * Onboarding Started through Approved: the provider is becoming a partner.
     */
    public function isOnboarding(): bool
    {
        return in_array($this, self::onboarding(), true);
    }

    /**
     * @return list<EngagementStage>
     */
    public static function onboarding(): array
    {
        return [self::OnboardingStarted, self::OnboardingSubmitted, self::Verification, self::Approved];
    }

    /**
     * The registry stage that matches a tourlast.com onboarding status, or
     * null when the status says nothing about the stage (rejected).
     */
    public static function fromOnboarding(OnboardingStatus $status): ?self
    {
        return match ($status) {
            OnboardingStatus::Submitted => self::OnboardingSubmitted,
            OnboardingStatus::UnderReview => self::Verification,
            OnboardingStatus::Approved => self::Approved,
            OnboardingStatus::Active => self::Live,
            OnboardingStatus::Rejected => null,
        };
    }
}
