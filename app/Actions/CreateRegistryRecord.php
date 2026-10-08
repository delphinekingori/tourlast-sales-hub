<?php

namespace App\Actions;

use App\Enums\EngagementSource;
use App\Enums\EngagementStage;
use App\Enums\EngagementStatus;
use App\Enums\LeadStatus;
use App\Enums\OnboardingStatus;
use App\Models\Lead;
use App\Models\Onboarding;
use App\Models\PropertyEngagement;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Start a Property Engagement Registry record from something the Hub already
 * knows: a salesperson's lead, or a tourlast.com signup that came with a
 * salesperson's referral code.
 */
class CreateRegistryRecord
{
    public function __construct(private SavePropertyEngagement $save) {}

    /**
     * Copy a lead into the registry and link the two. The lead's owner becomes
     * the record's representative.
     */
    public function fromLead(Lead $lead, ?User $by): PropertyEngagement
    {
        [$stage, $status] = match ($lead->status) {
            LeadStatus::New => [EngagementStage::NotContacted, EngagementStatus::Active],
            LeadStatus::Contacted => [EngagementStage::Contacted, EngagementStatus::Active],
            LeadStatus::Meeting => [EngagementStage::MeetingScheduled, EngagementStatus::Active],
            LeadStatus::LinkSent => [EngagementStage::OnboardingStarted, EngagementStatus::Active],
            LeadStatus::Onboarded => [EngagementStage::Live, EngagementStatus::Won],
            LeadStatus::Lost => [EngagementStage::Contacted, EngagementStatus::Lost],
        };

        $nextFollowUp = $lead->nextFollowUp;
        $first = CarbonImmutable::parse($lead->created_at)->startOfDay();

        $details = [
            'name' => $lead->business_name,
            'trading_name' => $lead->trading_name,
            'property_type' => $lead->property_type ?: 'other',
            'website' => $lead->website,
            'registration_number' => $lead->registration_number,
            'kra_pin' => $lead->kra_pin,
            ...$this->location($lead->location),
            'sales_rep_id' => $lead->user_id,
            'stage' => $stage,
            'status' => $status,
            'source' => EngagementSource::Prospecting,
            'summary' => $lead->notes,
            'next_action' => $nextFollowUp?->task,
            'next_action_on' => $nextFollowUp?->due_at?->toDateString(),
            'first_engaged_on' => $first,
            'last_engaged_on' => $lead->last_contacted_at ? CarbonImmutable::parse($lead->last_contacted_at)->startOfDay() : $first,
        ];

        if ($status === EngagementStatus::Lost) {
            $details['outcome'] = [
                'objection' => $lead->objection,
                'competitor' => $lead->competitor,
                'notes' => $lead->lost_notes,
                'reengage_on' => $lead->reengage_on,
            ];
        }

        $contact = [
            'name' => $lead->contact_name ?: 'Not provided',
            'title' => $lead->contact_role,
            'phone' => $lead->contact_phone,
            'whatsapp' => null,
            'email' => $lead->contact_email,
        ];

        $engagement = $this->save->create($details, $contact, $by);
        $lead->forceFill(['property_engagement_id' => $engagement->id])->saveQuietly();

        return $engagement;
    }

    /**
     * Start a record for a signup credited to a salesperson. The caller then
     * moves its stage and status to match the signup.
     */
    public function fromOnboarding(Onboarding $onboarding): PropertyEngagement
    {
        $first = CarbonImmutable::parse($onboarding->submitted_at ?? now())->startOfDay();

        $engagement = $this->save->create([
            'name' => $onboarding->property_name,
            'trading_name' => null,
            'registration_name' => $onboarding->legal_name,
            'property_type' => $onboarding->property_type ?: 'other',
            'tourlast_property_id' => $onboarding->tourlast_property_id,
            ...$this->location($onboarding->location),
            'sales_rep_id' => $onboarding->user_id,
            'stage' => $onboarding->status === OnboardingStatus::Inactive ? EngagementStage::Live : EngagementStage::OnboardingStarted,
            'status' => EngagementStatus::Active,
            'source' => EngagementSource::Referral,
            'summary' => 'Added automatically from a tourlast.com signup through '.($onboarding->user?->name ?? 'a salesperson').'\'s referral link.',
            'first_engaged_on' => $first,
            'last_engaged_on' => $first,
        ], [
            'name' => $onboarding->contact_name ?: 'Not provided',
            'title' => null,
            'phone' => $onboarding->contact_phone,
            'whatsapp' => null,
            'email' => $onboarding->contact_email,
        ], null);

        Lead::query()
            ->where('onboarding_id', $onboarding->id)
            ->whereNull('property_engagement_id')
            ->update(['property_engagement_id' => $engagement->id]);

        return $engagement;
    }

    /**
     * "Diani, Kenya" or "Nyali, Mombasa" into the registry's country, region, city and area.
     *
     * @return array{country: string, region: string, city: string, area: ?string}
     */
    private function location(?string $location): array
    {
        $country = config('hub.default_country');
        $parts = collect(explode(',', (string) $location))->map(fn (string $part) => trim($part))->filter();

        if ($parts->count() > 1 && strcasecmp((string) $parts->last(), $country) === 0) {
            $parts->pop();
        }

        $parts = $parts->values();
        $city = $parts->first() ?: 'Not specified';

        return [
            'country' => $country,
            'region' => $parts->count() > 1 ? $parts->last() : $city,
            'city' => $city,
            'area' => $parts->count() > 2 ? $parts->get(1) : null,
        ];
    }
}
