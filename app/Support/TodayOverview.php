<?php

namespace App\Support;

use App\Models\FollowUp;
use App\Models\Onboarding;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * A salesperson's day: the "Today" counts and schedule, shared by the web
 * dashboard and the API so both always agree.
 */
class TodayOverview
{
    /**
     * @return array{follow_ups: int, meetings: int, overdue: int, onboardings: int}
     */
    public static function counts(User $user): array
    {
        $today = FollowUp::query()->open()->where('user_id', $user->id)->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()]);
        $meetingTypes = array_map(fn ($type) => $type->value, FollowUp::meetingTypes());

        return [
            'follow_ups' => (clone $today)->whereNotIn('type', $meetingTypes)->count(),
            'meetings' => (clone $today)->whereIn('type', $meetingTypes)->count(),
            'overdue' => FollowUp::query()->open()->where('user_id', $user->id)->where('due_at', '<', now()->startOfDay())->count(),
            'onboardings' => Onboarding::query()->where('user_id', $user->id)->awaitingApproval()->count(),
        ];
    }

    /**
     * Today's items plus anything still open from earlier days.
     *
     * @return Collection<int, FollowUp>
     */
    public static function schedule(User $user, int $limit = 12): Collection
    {
        return FollowUp::query()
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query
                ->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()])
                ->orWhere(fn ($overdue) => $overdue->whereNull('completed_at')->where('due_at', '<', now()->startOfDay())))
            ->with('lead:id,business_name,location,property_engagement_id')
            ->chronological()
            ->limit($limit)
            ->get();
    }
}
