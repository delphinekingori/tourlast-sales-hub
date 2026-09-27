<?php

namespace App\Actions;

use App\Enums\EngagementEventType;
use App\Enums\EngagementStage;
use App\Enums\EngagementStatus;
use App\Enums\Objection;
use App\Models\PropertyEngagement;
use App\Models\PropertyEngagementEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class LogEngagement
{
    public function __construct(
        private AssignSalesRep $assignSalesRep,
        private TransitionEngagement $transition,
    ) {}

    /**
     * Add a touchpoint to the property's timeline. If a different salesperson
     * made it, they become the current representative; the earlier rep's
     * entries stay attributed to them.
     *
     * @param  array{objection?: ?Objection, competitor?: ?string, notes?: ?string, reengage_on?: ?CarbonImmutable}|null  $outcome
     */
    public function handle(
        PropertyEngagement $engagement,
        User $by,
        EngagementEventType $type,
        CarbonImmutable $happenedAt,
        ?User $rep = null,
        ?string $summary = null,
        ?string $notes = null,
        ?EngagementStage $stage = null,
        ?EngagementStatus $status = null,
        ?string $nextAction = null,
        ?CarbonImmutable $nextActionOn = null,
        ?array $outcome = null,
    ): PropertyEngagementEvent {
        return DB::transaction(function () use ($engagement, $by, $type, $happenedAt, $rep, $summary, $notes, $stage, $status, $nextAction, $nextActionOn, $outcome): PropertyEngagementEvent {
            if ($rep && $rep->id !== $engagement->sales_rep_id) {
                $this->assignSalesRep->handle($engagement, $rep, $by, $happenedAt, 'other', 'Took over while logging a '.strtolower($type->label()).'.');
            }

            $event = $engagement->events()->create([
                'type' => $type,
                'sales_rep_id' => $rep?->id ?? $engagement->sales_rep_id,
                'recorded_by' => $by->id,
                'summary' => $summary,
                'notes' => $notes,
                'happened_at' => $happenedAt,
            ]);

            if ($type->isInteraction() && (! $engagement->last_engaged_on || $happenedAt->startOfDay()->gt($engagement->last_engaged_on))) {
                $engagement->last_engaged_on = $happenedAt->toDateString();
            }

            if ($nextAction !== null || $nextActionOn !== null) {
                $engagement->next_action = $nextAction;
                $engagement->next_action_on = $nextActionOn?->toDateString();
            }

            $engagement->updated_by = $by->id;
            $engagement->save();

            $this->transition->handle($engagement, $stage, $status, $by, outcome: $outcome);

            return $event;
        });
    }
}
