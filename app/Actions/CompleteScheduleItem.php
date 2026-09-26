<?php

namespace App\Actions;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\FollowUp;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CompleteScheduleItem
{
    public function __construct(private LogActivity $logActivity) {}

    /**
     * Mark a scheduled call, meeting or visit as done. Its outcome is logged
     * on the lead as an activity (so it shows in the lead's and the property's
     * history), and the next follow-up is booked when one is given.
     */
    public function handle(
        FollowUp $item,
        User $user,
        ?string $outcome = null,
        ?string $nextAction = null,
        ?CarbonImmutable $nextAt = null,
        bool $nextHasTime = false,
        ?ActivityType $nextType = null,
    ): ?Activity {
        return DB::transaction(function () use ($item, $user, $outcome, $nextAction, $nextAt, $nextHasTime, $nextType): ?Activity {
            $activity = null;

            // A plain reminder with nothing to report just closes; anything else becomes history.
            if ($item->type !== ActivityType::FollowUp || filled($outcome) || $nextAt) {
                $happenedAt = $item->has_time && $item->due_at->isPast() ? $item->due_at->toImmutable() : CarbonImmutable::now();

                $activity = $this->logActivity->handle(
                    $item->lead,
                    $user,
                    $item->type,
                    $happenedAt,
                    trim($item->task.($outcome ? ': '.$outcome : '')),
                    $nextAction,
                    $nextAt,
                    $nextType,
                    $nextHasTime,
                );
            }

            $item->forceFill(['completed_at' => now(), 'outcome_activity_id' => $activity?->id])->save();

            return $activity;
        });
    }
}
