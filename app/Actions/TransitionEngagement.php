<?php

namespace App\Actions;

use App\Enums\EngagementEventType;
use App\Enums\EngagementStage;
use App\Enums\EngagementStatus;
use App\Enums\Objection;
use App\Models\PropertyEngagement;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class TransitionEngagement
{
    /**
     * Statuses that close or pause an engagement and carry an outcome.
     */
    public const Closing = [EngagementStatus::Lost, EngagementStatus::Rejected, EngagementStatus::Closed, EngagementStatus::Stalled];

    /**
     * Move the engagement to a new stage and/or status, writing one timeline
     * entry per change with the previous and new value. For a closing status,
     * $outcome records why (objection, competitor, notes) and when to try again.
     *
     * @param  array{objection?: ?Objection, competitor?: ?string, notes?: ?string, reengage_on?: ?CarbonInterface}|null  $outcome
     * @return bool Whether anything changed.
     */
    public function handle(
        PropertyEngagement $engagement,
        ?EngagementStage $stage,
        ?EngagementStatus $status,
        ?User $by,
        EngagementEventType $stageEvent = EngagementEventType::StageChanged,
        ?string $summary = null,
        ?array $outcome = null,
    ): bool {
        $stageChanged = $stage && $stage !== $engagement->stage;
        $statusChanged = $status && $status !== $engagement->status;

        if (! $stageChanged && ! $statusChanged) {
            return false;
        }

        DB::transaction(function () use ($engagement, $stage, $status, $by, $stageEvent, $summary, $stageChanged, $statusChanged, $outcome): void {
            $fromStage = $engagement->stage;
            $fromStatus = $engagement->status;

            $engagement->forceFill(array_filter([
                'stage' => $stageChanged ? $stage : null,
                'status' => $statusChanged ? $status : null,
                'updated_by' => $by?->id,
            ]));

            if ($statusChanged && in_array($status, self::Closing, true)) {
                $engagement->forceFill([
                    'objection' => $outcome['objection'] ?? null,
                    'competitor' => $outcome['competitor'] ?? null,
                    'outcome_notes' => $outcome['notes'] ?? null,
                    'reengage_on' => ($outcome['reengage_on'] ?? null)?->toDateString(),
                    'closed_at' => $status === EngagementStatus::Stalled ? $engagement->closed_at : now(),
                ]);
            } elseif ($statusChanged) {
                // Back in play: the objection stays on record, the reminder is done.
                $engagement->forceFill(['reengage_on' => null]);
            }

            $engagement->save();

            if ($stageChanged) {
                $engagement->events()->create([
                    'type' => $stageEvent,
                    'sales_rep_id' => $engagement->sales_rep_id,
                    'recorded_by' => $by?->id,
                    'from_value' => $fromStage?->value,
                    'to_value' => $stage->value,
                    'summary' => $summary,
                    'happened_at' => now(),
                ]);
            }

            if ($statusChanged) {
                $objection = $outcome['objection'] ?? null;
                $reengage = $outcome['reengage_on'] ?? null;

                $engagement->events()->create([
                    'type' => EngagementEventType::StatusChanged,
                    'sales_rep_id' => $engagement->sales_rep_id,
                    'recorded_by' => $by?->id,
                    'from_value' => $fromStatus?->value,
                    'to_value' => $status->value,
                    'summary' => collect([
                        $stageChanged ? null : $summary,
                        $objection ? 'Objection: '.$objection->label().(($outcome['competitor'] ?? null) ? ' ('.$outcome['competitor'].')' : '') : null,
                        $reengage ? 'Re-engage '.$reengage->format('j M Y') : null,
                    ])->filter()->implode(' · ') ?: null,
                    'notes' => $outcome['notes'] ?? null,
                    'changes' => $objection || $reengage ? array_filter([
                        'objection' => $objection?->value,
                        'competitor' => $outcome['competitor'] ?? null,
                        'reengage_on' => $reengage?->toDateString(),
                    ]) : null,
                    'happened_at' => now(),
                ]);
            }
        });

        return true;
    }
}
