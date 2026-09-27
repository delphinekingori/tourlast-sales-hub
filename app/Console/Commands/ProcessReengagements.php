<?php

namespace App\Console\Commands;

use App\Actions\TransitionEngagement;
use App\Enums\ActivityType;
use App\Enums\EngagementStatus;
use App\Models\Lead;
use App\Models\PropertyEngagement;
use App\Support\Alerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hub:process-reengagements')]
#[Description('Bring lost, stalled and closed registry properties back to Re-engage on their re-engage date')]
class ProcessReengagements extends Command
{
    public function handle(TransitionEngagement $transition): int
    {
        $due = PropertyEngagement::query()
            ->with('salesRep')
            ->whereNotNull('reengage_on')
            ->whereDate('reengage_on', '<=', now()->toDateString())
            ->whereIn('status', array_map(fn (EngagementStatus $status) => $status->value, TransitionEngagement::Closing))
            ->get();

        foreach ($due as $engagement) {
            $was = $engagement->status->label().($engagement->objection ? ' ('.$engagement->objection->label().')' : '');

            $transition->handle($engagement, null, EngagementStatus::ReEngage, null, summary: 'Re-engagement due · was '.$was);

            // A sales action for the representative, on their lead for this property.
            $lead = $engagement->sales_rep_id
                ? Lead::query()->where('property_engagement_id', $engagement->id)->where('user_id', $engagement->sales_rep_id)->first()
                : null;

            if ($lead && ! $lead->followUps()->whereNull('completed_at')->where('task', 'Re-engage after loss')->exists()) {
                $lead->followUps()->create([
                    'user_id' => $lead->user_id,
                    'type' => ActivityType::FollowUp,
                    'task' => 'Re-engage after loss',
                    'due_at' => now()->startOfDay(),
                    'contact_name' => $lead->contact_name,
                    'contact_role' => $lead->contact_role,
                    'notes' => 'Registry: '.$was.'.',
                ]);
            }

            Alerts::send(
                'reengage_due',
                'Re-engagement due',
                "{$engagement->name} is due for re-engagement today (was {$was}).".($engagement->salesRep ? " Representative: {$engagement->salesRep->name}." : ' No representative assigned.'),
                route('registry.show', $engagement->id),
                $engagement->salesRep,
            );
        }

        $this->components->info("{$due->count()} ".str('property')->plural($due->count()).' moved to Re-engage.');

        return self::SUCCESS;
    }
}
