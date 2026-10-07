<?php

namespace App\Actions;

use App\Enums\EngagementEventType;
use App\Enums\EngagementStage;
use App\Enums\EngagementStatus;
use App\Enums\OnboardingStatus;
use App\Models\Onboarding;
use App\Models\PropertyEngagement;

class SyncOnboardingToRegistry
{
    /**
     * Keep the registry in step with tourlast.com. When an onboarding belongs
     * to a registry record (directly, through its lead, or by tourlast.com
     * property ID), link them and move the record's stage to match.
     *
     * Records are never created here: the registry is curated by managers.
     * A rejected or inactive onboarding says nothing about the stage, and an
     * inactive one never overwrites the record's own status either.
     */
    public function handle(Onboarding $onboarding): ?PropertyEngagement
    {
        $engagement = $this->find($onboarding);

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
        $status = match (true) {
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

        return PropertyEngagement::query()->where('tourlast_property_id', $onboarding->tourlast_property_id)->first();
    }
}
