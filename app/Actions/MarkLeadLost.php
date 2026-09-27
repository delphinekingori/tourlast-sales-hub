<?php

namespace App\Actions;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\Objection;
use App\Models\Lead;
use App\Models\User;
use App\Support\Alerts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MarkLeadLost
{
    /**
     * Close a lead as lost with the reason on record. A re-engage date books
     * a future sales action in the owner's schedule, so the property comes
     * back round instead of disappearing.
     */
    public function handle(Lead $lead, User $by, Objection $objection, ?string $competitor, ?string $notes, ?CarbonImmutable $reengageOn): Lead
    {
        return DB::transaction(function () use ($lead, $by, $objection, $competitor, $notes, $reengageOn): Lead {
            $lead->followUps()->whereNull('completed_at')->where('task', 'like', 'Re-engage%')->delete();

            $lead->update([
                'status' => LeadStatus::Lost,
                'objection' => $objection,
                'competitor' => $competitor,
                'lost_notes' => $notes,
                'lost_reason' => $objection->label().($competitor ? ' ('.$competitor.')' : ''),
                'lost_at' => now(),
                'reengage_on' => $reengageOn?->toDateString(),
            ]);

            if ($reengageOn) {
                $lead->followUps()->create([
                    'user_id' => $lead->user_id,
                    'type' => ActivityType::FollowUp,
                    'task' => 'Re-engage after loss',
                    'due_at' => $reengageOn->startOfDay(),
                    'has_time' => false,
                    'contact_name' => $lead->contact_name,
                    'contact_role' => $lead->contact_role,
                    'notes' => 'Lost '.now()->format('F Y').': '.$lead->lost_reason.($notes ? '. '.$notes : ''),
                ]);
            }

            Alerts::send(
                'deal_lost',
                'Deal lost',
                "{$lead->business_name} was marked lost by {$by->name}: {$lead->lost_reason}".($reengageOn ? '. Re-engage '.$reengageOn->format('F Y').'.' : '.'),
                route('leads.show', $lead),
            );

            return $lead;
        });
    }
}
