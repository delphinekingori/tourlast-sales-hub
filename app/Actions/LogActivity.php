<?php

namespace App\Actions;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class LogActivity
{
    /**
     * Record a touchpoint with a lead, optionally scheduling the next follow-up.
     *
     * A New lead moves to Contacted automatically, and a Meeting or Site visit
     * moves an earlier-stage lead to Meeting.
     */
    public function handle(
        Lead $lead,
        User $user,
        ActivityType $type,
        CarbonImmutable $happenedAt,
        ?string $notes = null,
        ?string $nextAction = null,
        ?CarbonImmutable $followUpAt = null,
        ?ActivityType $followUpType = null,
        bool $followUpHasTime = false,
    ): Activity {
        return DB::transaction(function () use ($lead, $user, $type, $happenedAt, $notes, $nextAction, $followUpAt, $followUpType, $followUpHasTime): Activity {
            $activity = $lead->activities()->create([
                'user_id' => $user->id,
                'type' => $type,
                'happened_at' => $happenedAt,
                'notes' => $notes,
                'next_action' => $nextAction,
            ]);

            if (! $lead->last_contacted_at || $happenedAt->gt($lead->last_contacted_at)) {
                $lead->last_contacted_at = $happenedAt;
            }

            if ($lead->status === LeadStatus::New) {
                $lead->status = LeadStatus::Contacted;
            }

            if (in_array($type, [ActivityType::Meeting, ActivityType::SiteVisit, ActivityType::Demo], true)
                && in_array($lead->status, [LeadStatus::New, LeadStatus::Contacted], true)) {
                $lead->status = LeadStatus::Meeting;
            }

            $lead->save();

            if ($followUpAt) {
                $lead->followUps()->create([
                    'user_id' => $user->id,
                    'type' => $followUpType ?? ActivityType::FollowUp,
                    'task' => $nextAction ?: 'Follow up after '.strtolower($type->label()),
                    'due_at' => $followUpHasTime ? $followUpAt : $followUpAt->startOfDay(),
                    'has_time' => $followUpHasTime,
                    'contact_name' => $lead->contact_name,
                    'contact_role' => $lead->contact_role,
                ]);
            }

            return $activity;
        });
    }
}
