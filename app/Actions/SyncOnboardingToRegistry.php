<?php

namespace App\Actions;

use App\Enums\EngagementEventType;
use App\Enums\EngagementStage;
use App\Enums\EngagementStatus;
use App\Enums\OnboardingStatus;
use App\Models\Onboarding;
use App\Models\PropertyEngagement;
use App\Models\PropertyEngagementContact;

class SyncOnboardingToRegistry
{
    public function __construct(private CreateRegistryRecord $createRecord) {}

    /**
     * Keep the registry in step with tourlast.com. When an onboarding belongs
     * to a registry record (directly, through its lead, by tourlast.com
     * property ID, or because the same business is already in the registry),
     * link them and move the record's stage to match.
     *
     * A signup credited to a salesperson with no record yet gets one, so what
     * the team earns shows up without anyone typing it in. A signup nobody is
     * credited for stays out until an admin assigns it, and a record a manager
     * archived is never brought back. A rejected or inactive onboarding says
     * nothing about the stage, and an inactive one never overwrites the
     * record's own status either.
     */
    public function handle(Onboarding $onboarding): ?PropertyEngagement
    {
        $engagement = $this->find($onboarding) ?? $this->startFor($onboarding);

        if (! $engagement) {
            return null;
        }

        if ($onboarding->property_engagement_id !== $engagement->id) {
            $onboarding->forceFill(['property_engagement_id' => $engagement->id])->saveQuietly();

            $engagement->events()->create([
                'type' => EngagementEventType::Linked,
                'sales_rep_id' => $onboarding->user_id ?? $engagement->sales_rep_id,
                'summary' => 'tourlast.com signup '.$onboarding->tourlast_property_id.' linked',
                'happened_at' => now(),
            ]);
        }

        if (blank($engagement->tourlast_property_id)) {
            $engagement->forceFill(['tourlast_property_id' => $onboarding->tourlast_property_id])->save();
        }

        $stage = EngagementStage::fromOnboarding($onboarding->status);
        $reviewFailed = $engagement->status === EngagementStatus::Lost && $onboarding->partnerAccount?->review_failed_at !== null;
        $status = match (true) {
            $reviewFailed => null,
            $onboarding->status === OnboardingStatus::Active => EngagementStatus::Won,
            $onboarding->status === OnboardingStatus::Rejected => EngagementStatus::Rejected,
            $onboarding->status === OnboardingStatus::Inactive => null,
            in_array($engagement->status, [EngagementStatus::Stalled, EngagementStatus::ReEngage], true) => null,
            default => EngagementStatus::Active,
        };

        $fromStage = $engagement->stage;
        $fromStatus = $engagement->status;
        $stageChanged = $stage && $stage !== $fromStage;
        $statusChanged = $status && $status !== $fromStatus;

        if ($stageChanged || $statusChanged) {
            $engagement->forceFill(array_filter([
                'stage' => $stageChanged ? $stage : null,
                'status' => $statusChanged ? $status : null,
            ]))->save();

            $engagement->events()->create([
                'type' => EngagementEventType::Onboarding,
                'sales_rep_id' => $onboarding->user_id ?? $engagement->sales_rep_id,
                'from_value' => $stageChanged ? $fromStage->value : null,
                'to_value' => $stageChanged ? $stage->value : null,
                'summary' => 'tourlast.com status: '.$onboarding->status->label()
                    .($statusChanged ? ' · registry status '.$fromStatus->label().' → '.$status->label() : ''),
                'happened_at' => now(),
            ]);
        }

        return $engagement;
    }

    private function find(Onboarding $onboarding): ?PropertyEngagement
    {
        if ($onboarding->property_engagement_id) {
            return PropertyEngagement::query()->find($onboarding->property_engagement_id);
        }

        $viaLead = $onboarding->lead()->whereNotNull('property_engagement_id')->value('property_engagement_id');

        if ($viaLead) {
            return PropertyEngagement::query()->find($viaLead);
        }

        return PropertyEngagement::query()->where('tourlast_property_id', $onboarding->tourlast_property_id)->first()
            ?? $this->sameBusiness($onboarding);
    }

    /**
     * An unlinked record for the same business: same name in the same city, or the same
     * contact phone or email. Anything vaguer is left for a manager to link by hand.
     */
    private function sameBusiness(Onboarding $onboarding): ?PropertyEngagement
    {
        $nameKey = PropertyEngagement::nameKey($onboarding->property_name);
        $city = trim(explode(',', (string) $onboarding->location)[0]);

        $email = filled($onboarding->contact_email) ? strtolower($onboarding->contact_email) : null;
        $phone = PropertyEngagementContact::phoneKey($onboarding->contact_phone);

        if ($nameKey === '' || ($city === '' && ! $email && ! $phone)) {
            return null;
        }

        return PropertyEngagement::query()
            ->whereNull('tourlast_property_id')
            ->where('name_key', $nameKey)
            ->where(function ($query) use ($city, $email, $phone): void {
                $query->when($city !== '', fn ($query) => $query->orWhere('city', $city))
                    ->when($email || $phone, fn ($query) => $query->orWhereHas('contacts', function ($contacts) use ($email, $phone): void {
                        $contacts->where(function ($match) use ($email, $phone): void {
                            $match->when($email, fn ($query) => $query->orWhere('email', $email))
                                ->when($phone, fn ($query) => $query->orWhere('phone_key', $phone));
                        });
                    }));
            })
            ->oldest('id')
            ->first();
    }

    /**
     * Add the signup to the registry when a salesperson is credited and no manager has
     * archived a record for it before.
     */
    private function startFor(Onboarding $onboarding): ?PropertyEngagement
    {
        if (! $onboarding->user_id || $onboarding->trashed()) {
            return null;
        }

        $archived = PropertyEngagement::onlyTrashed()->where('tourlast_property_id', $onboarding->tourlast_property_id)->exists();

        return $archived ? null : $this->createRecord->fromOnboarding($onboarding);
    }
}
