<?php

namespace App\Actions;

use App\Enums\EngagementEventType;
use App\Models\LeadTransfer;
use App\Models\PropertyEngagement;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class AssignSalesRep
{
    /**
     * Assign the property to $rep, or transfer it from the current rep. The
     * previous rep's period is closed, not deleted, so the history of who
     * engaged it stays; the reason is kept on the new period and the timeline.
     */
    public function handle(PropertyEngagement $engagement, ?User $rep, ?User $by, CarbonInterface $on, ?string $reason = null, ?string $notes = null): bool
    {
        if ($engagement->sales_rep_id === $rep?->id) {
            return false;
        }

        return DB::transaction(function () use ($engagement, $rep, $by, $on, $reason, $notes): bool {
            $previous = $engagement->salesRep;

            $engagement->reps()->whereNull('ended_on')->update(['ended_on' => $on->toDateString()]);

            if ($rep) {
                $engagement->reps()->create([
                    'user_id' => $rep->id,
                    'started_on' => $on->toDateString(),
                    'assigned_by' => $by?->id,
                    'reason' => $reason,
                    'notes' => $notes,
                ]);
            }

            $engagement->forceFill(['sales_rep_id' => $rep?->id, 'updated_by' => $by?->id ?? $engagement->updated_by])->save();
            $engagement->setRelation('salesRep', $rep);

            $engagement->events()->create([
                'type' => EngagementEventType::RepChanged,
                'sales_rep_id' => $rep?->id,
                'recorded_by' => $by?->id,
                'from_value' => $previous?->name,
                'to_value' => $rep?->name,
                'summary' => $previous ? ($reason ? 'Transferred · '.LeadTransfer::reasonLabelFor($reason) : 'Transferred') : 'Property assigned',
                'notes' => $notes,
                'happened_at' => now(),
            ]);

            return true;
        });
    }
}
