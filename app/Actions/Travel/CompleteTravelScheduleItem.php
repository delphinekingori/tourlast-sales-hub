<?php

namespace App\Actions\Travel;

use App\Enums\ActivityType;
use App\Models\FollowUp;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Marks a Travel Sales calendar item done, keeps the outcome on the item and
 * books the next follow-up on the same record when one is given.
 */
class CompleteTravelScheduleItem
{
    public function handle(
        FollowUp $item,
        User $user,
        ?string $outcome = null,
        ?string $nextAction = null,
        ?CarbonImmutable $nextAt = null,
        bool $nextHasTime = false,
        ?ActivityType $nextType = null,
    ): ?FollowUp {
        abort_unless($item->user_id === $user->id && $item->lead_id === null, 403);

        return DB::transaction(function () use ($item, $outcome, $nextAction, $nextAt, $nextHasTime, $nextType): ?FollowUp {
            $notes = trim((string) $item->notes);

            if (filled($outcome)) {
                $notes = trim($notes."\n\nOutcome ".now()->format('j M Y').': '.$outcome);
            }

            $item->forceFill(['completed_at' => now(), 'notes' => $notes ?: null])->save();

            if (! $nextAt) {
                return null;
            }

            return FollowUp::query()->create([
                'user_id' => $item->user_id,
                'subject_type' => $item->subject_type,
                'subject_id' => $item->subject_id,
                'type' => $nextType ?? ActivityType::CustomerFollowUp,
                'task' => $nextAction ?: 'Follow up: '.$item->task,
                'due_at' => $nextHasTime ? $nextAt : $nextAt->startOfDay(),
                'has_time' => $nextHasTime,
                'contact_name' => $item->contact_name,
                'contact_role' => $item->contact_role,
            ]);
        });
    }
}
